<?php

namespace App\Services\YandexMaps;

use App\Enums\FetchMode;
use App\Services\YandexMaps\Data\OrganizationData;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Выгрузка отзывов организации.
 *
 * Источник отдаёт не больше 600 отзывов на одну выдачу — дальше его бэкенд отвечает ошибкой.
 * Это проверено и на сайте, и в браузере, обойти нельзя. Зато выдачу можно запросить в другом
 * порядке, и тогда в те же 600 попадут другие отзывы: срезы «сначала худшие» и «сначала лучшие»
 * не пересекаются вовсе. Поэтому в максимальном режиме мы проходим несколько сортировок подряд
 * и останавливаемся, как только собрали столько, сколько обещает счётчик карточки.
 */
class ReviewsScraper
{
    public function __construct(
        private readonly Transport $transport,
        private readonly ReviewsApi $api,
    ) {}

    public static function make(): self
    {
        $transport = Transport::make();

        return new self($transport, new ReviewsApi($transport));
    }

    /**
     * @param  Closure(array, int, int): void  $onBatch  новые отзывы, собрано всего, ожидается всего
     */
    public function scrape(OrganizationLink $link, FetchMode $mode, Closure $onBatch): OrganizationData
    {
        $url = $link->canonicalUrl();
        $page = ReviewsPage::parse($this->transport->get($url)->body(), $url);

        $organization = $page->organization;
        $expected = $organization->reviewsCount ?? 0;

        if ($page->context === null) {
            // Без токенов доступен только первый экран отзывов — отдаём хотя бы его.
            Log::warning('На странице не нашлось токенов для догрузки отзывов', ['организация' => $link->id]);
            $onBatch($page->reviews, count($page->reviews), $expected);

            return $organization;
        }

        $seen = [];

        foreach ($mode->rankings() as $ranking) {
            $this->scrapeRanking($page->context, $ranking, $seen, $expected, $onBatch);

            // Набрали столько, сколько обещает карточка — остальные срезы только повторят уже собранное.
            if ($expected > 0 && count($seen) >= $expected) {
                break;
            }
        }

        Log::info('Выгрузка отзывов завершена', [
            'организация' => $link->id,
            'собрано' => count($seen),
            'по данным карточки' => $expected,
        ]);

        return $organization;
    }

    private function scrapeRanking(
        PageContext $context,
        string $ranking,
        array &$seen,
        int $expected,
        Closure $onBatch,
    ): void {
        $limit = $this->pageLimit();

        for ($page = 1; $page <= $limit; $page++) {
            try {
                $reviews = $this->api->page($context, $ranking, $page);
            } catch (ReviewsDepthLimitReached $e) {
                Log::info('Срез отзывов закончился', [
                    'сортировка' => $ranking,
                    'страница' => $page,
                    'причина' => $e->getMessage(),
                ]);

                return;
            }

            if ($reviews === []) {
                return;
            }

            // Срезы сильно пересекаются между собой, поэтому наружу отдаём только новое —
            // иначе счётчик собранного врал бы в несколько раз.
            $fresh = [];

            foreach ($reviews as $review) {
                if (! isset($seen[$review->id])) {
                    $seen[$review->id] = true;
                    $fresh[] = $review;
                }
            }

            if ($fresh !== []) {
                $onBatch($fresh, count($seen), $expected);
            }

            if ($expected > 0 && count($seen) >= $expected) {
                return;
            }
        }
    }

    /**
     * Глубина одной выдачи упирается в 600 отзывов, то есть 12 страниц по 50.
     * Настройка нужна для пробных прогонов, когда выгребать карточку целиком незачем.
     */
    private function pageLimit(): int
    {
        $configured = (int) config('parsing.max_pages');

        return $configured > 0 ? min($configured, 12) : 12;
    }
}
