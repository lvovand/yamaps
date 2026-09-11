<?php

namespace App\Services\YandexMaps;

use App\Services\YandexMaps\Data\OrganizationData;
use App\Services\YandexMaps\Data\ReviewData;
use App\Services\YandexMaps\Exceptions\SourceChanged;
use Carbon\CarbonImmutable;

/**
 * Одна страница отзывов, разобранная из HTML.
 *
 * Яндекс кладёт в страницу состояние своего приложения целиком — готовый JSON в теге
 * <script class="state-view">. Мы читаем именно его, а не вёрстку: классы и порядок блоков
 * на странице меняются постоянно, а структура состояния живёт заметно дольше.
 *
 * Каждое обращение к полям проверяется: если ожидаемого поля нет, это не «ноль отзывов»,
 * а сломанный парсер, и он обязан сказать об этом вслух.
 */
final readonly class ReviewsPage
{
    /**
     * @param  ReviewData[]  $reviews
     */
    private function __construct(
        public OrganizationData $organization,
        public array $reviews,
        public int $page,
        public int $totalPages,
        public bool $hasReviewsBlock,
        public ?PageContext $context,
    ) {}

    public static function parse(string $html, string $pageUrl = ''): self
    {
        $state = self::extractState($html);

        $item = data_get($state, 'stack.0.results.items.0');

        if (! is_array($item) || ! isset($item['id'])) {
            throw new SourceChanged('В состоянии страницы нет карточки организации');
        }

        $organization = new OrganizationData(
            id: (string) $item['id'],
            name: (string) ($item['title'] ?? ''),
            rating: self::rating($item),
            ratingsCount: self::intOrNull(data_get($item, 'ratingData.ratingCount')),
            reviewsCount: self::intOrNull(data_get($item, 'ratingData.reviewCount')),
        );

        $context = self::context($state, (string) $item['id'], $pageUrl);

        $params = data_get($item, 'reviewResults.params');
        $reviews = data_get($item, 'reviewResults.reviews');

        // Дальше шестисот отзывов Яндекс отдаёт карточку вообще без блока отзывов:
        // код 200, капчи нет, просто данных больше не будет. Отличать это от поломки
        // разбора должен вызывающий код — ему видно, на какой странице мы находимся.
        if (! is_array($params) || ! is_array($reviews)) {
            return new self($organization, [], 0, 0, hasReviewsBlock: false, context: $context);
        }

        if (! isset($params['page'], $params['totalPages'])) {
            throw new SourceChanged('В блоке отзывов нет параметров пагинации');
        }

        return new self(
            organization: $organization,
            reviews: array_values(array_filter(array_map(self::review(...), $reviews))),
            page: (int) $params['page'],
            totalPages: (int) $params['totalPages'],
            hasReviewsBlock: true,
            context: $context,
        );
    }

    /**
     * Токены для обращения к внутреннему эндпоинту. Их отсутствие не мешает прочитать
     * показатели организации, поэтому это не ошибка — просто листать срезами будет нечем.
     */
    private static function context(array $state, string $businessId, string $pageUrl): ?PageContext
    {
        $csrf = data_get($state, 'config.csrfToken');
        // Идентификатор сессии страница кладёт в блок счётчиков, а рядом дублирует в config.requestId —
        // берём оба места, это дешевле, чем ломаться из-за перестановки одного поля.
        $session = data_get($state, 'config.counters.analytics.sessionId')
            ?? data_get($state, 'config.requestId');
        $request = data_get($state, 'stack.0.results.requestId');

        if (! is_string($csrf) || ! is_string($session) || ! is_string($request)) {
            return null;
        }

        return new PageContext($businessId, $csrf, $session, $request, $pageUrl);
    }

    private static function extractState(string $html): array
    {
        // Порядок атрибутов у тега непостоянен (встречается и type перед class), поэтому
        // цепляемся только за сам класс.
        if (preg_match('~<script[^>]*class="state-view"[^>]*>(.*?)</script>~s', $html, $m) !== 1) {
            throw new SourceChanged('На странице нет блока с состоянием приложения');
        }

        $state = json_decode(html_entity_decode($m[1]), true);

        if (! is_array($state)) {
            throw new SourceChanged('Состояние страницы не разбирается как JSON');
        }

        return $state;
    }

    private static function review(mixed $raw): ?ReviewData
    {
        // Отзыв без идентификатора нечем отличить от других при повторном парсинге,
        // поэтому такой пропускаем — но это не повод останавливать весь проход.
        if (! is_array($raw) || ! isset($raw['reviewId'])) {
            return null;
        }

        return new ReviewData(
            id: (string) $raw['reviewId'],
            author: data_get($raw, 'author.name'),
            authorPublicId: data_get($raw, 'author.publicId'),
            rating: self::intOrNull($raw['rating'] ?? null),
            text: isset($raw['text']) ? (string) $raw['text'] : null,
            publishedAt: self::time($raw['updatedTime'] ?? null),
        );
    }

    private static function rating(array $item): ?float
    {
        $value = data_get($item, 'ratingData.ratingValue');

        // Яндекс отдаёт рейтинг числом с плавающей точкой (4.900000095367432),
        // а показываем мы его с одним знаком — округляем сразу, чтобы не тащить мусор в базу.
        return is_numeric($value) ? round((float) $value, 1) : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
