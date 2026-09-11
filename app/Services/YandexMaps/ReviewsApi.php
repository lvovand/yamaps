<?php

namespace App\Services\YandexMaps;

use App\Services\YandexMaps\Data\ReviewData;
use App\Services\YandexMaps\Exceptions\SourceChanged;
use Carbon\CarbonImmutable;

/**
 * Внутренний эндпоинт карточки, который подгружает отзывы при прокрутке.
 *
 * Нужен нам ради одного параметра — порядка сортировки: обычная страница его в адресе
 * не принимает, а без разных порядков не обойти ограничение источника в 600 отзывов на выдачу.
 * Попутно он дешевле: чистый JSON вместо полутора мегабайт HTML.
 *
 * Запрос подписан: к параметрам добавляется s — хеш от строки запроса. Алгоритм восстановлен
 * по реальным запросам браузера, это классический djb2 с XOR. Без верной подписи бэкенд
 * отвечает 400 и ничего не объясняет.
 */
class ReviewsApi
{
    private const ENDPOINT = 'https://yandex.ru/maps/api/business/fetchReviews';

    public function __construct(private readonly Transport $transport) {}

    /**
     * @return ReviewData[]
     *
     * @throws ReviewsDepthLimitReached когда упёрлись в потолок выдачи
     */
    public function page(PageContext $context, string $ranking, int $page): array
    {
        $params = [
            'ajax' => '1',
            'businessId' => $context->businessId,
            'csrfToken' => $context->csrfToken,
            'locale' => 'ru_RU',
            'page' => (string) $page,
            // Любой размер порции, кроме 50, эндпоинт отвергает — проверено.
            'pageSize' => '50',
            'ranking' => $ranking,
            'reqId' => $context->requestId,
            'sessionId' => $context->sessionId,
        ];

        $response = $this->transport->get(self::ENDPOINT, $params + ['s' => self::sign($params)], [
            'Accept' => '*/*',
            'Referer' => $context->pageUrl,
            'X-Retpath-Y' => $context->pageUrl,
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
        ]);

        $body = $response->json();

        if (! is_array($body)) {
            throw new SourceChanged('Ответ эндпоинта отзывов не разбирается как JSON');
        }

        if (isset($body['error'])) {
            // За пределом выдачи источник отвечает внутренней ошибкой. Это не поломка
            // и не бан: просто дальше он не отдаёт.
            throw new ReviewsDepthLimitReached((string) ($body['error']['message'] ?? 'источник прекратил выдачу'));
        }

        // Когда токен не принят, эндпоинт молча возвращает новый вместо данных.
        if (isset($body['csrfToken']) && ! isset($body['data'])) {
            throw new SourceChanged('Эндпоинт отзывов не принял токен страницы');
        }

        $reviews = data_get($body, 'data.reviews');

        if (! is_array($reviews)) {
            throw new SourceChanged('В ответе эндпоинта нет списка отзывов');
        }

        return array_values(array_filter(array_map(self::review(...), $reviews)));
    }

    /**
     * Подпись запроса: djb2 с XOR от строки параметров, отсортированных по имени.
     */
    public static function sign(array $params): int
    {
        ksort($params);

        $hash = 5381;

        foreach (str_split(http_build_query($params, '', '&', PHP_QUERY_RFC3986)) as $char) {
            $hash = (($hash * 33) ^ ord($char)) & 0xFFFFFFFF;
        }

        return $hash;
    }

    private static function review(mixed $raw): ?ReviewData
    {
        if (! is_array($raw) || ! isset($raw['reviewId'])) {
            return null;
        }

        $time = $raw['updatedTime'] ?? null;

        return new ReviewData(
            id: (string) $raw['reviewId'],
            author: data_get($raw, 'author.name'),
            authorPublicId: data_get($raw, 'author.publicId'),
            rating: is_numeric($raw['rating'] ?? null) ? (int) $raw['rating'] : null,
            text: isset($raw['text']) ? (string) $raw['text'] : null,
            publishedAt: is_string($time) && $time !== '' ? CarbonImmutable::parse($time) : null,
        );
    }
}
