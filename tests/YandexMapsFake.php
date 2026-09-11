<?php

namespace Tests;

use Illuminate\Support\Facades\Http;

/**
 * Подмена Яндекса в тестах: страница карточки плюс внутренний эндпоинт отзывов.
 *
 * Срезы описываются как «сортировка → список страниц», где страница — это массив
 * идентификаторов отзывов либо строка 'depth-limit', если на этом месте источник
 * должен ответить ошибкой глубины.
 */
trait YandexMapsFake
{
    protected function fakeYandex(int $reviewCount, array $slices, array $firstScreen = []): void
    {
        Http::fake(function ($request) use ($reviewCount, $slices, $firstScreen) {
            if (! str_contains($request->url(), '/api/business/fetchReviews')) {
                return Http::response($this->cardHtml($reviewCount, true, $firstScreen));
            }

            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            $pages = $slices[$query['ranking'] ?? ''] ?? [];
            $page = $pages[((int) ($query['page'] ?? 1)) - 1] ?? [];

            if ($page === 'depth-limit') {
                return Http::response([
                    'error' => ['code' => 500, 'message' => 'Internal error in /business/fetchReviews'],
                ]);
            }

            return Http::response(['data' => ['reviews' => array_map($this->reviewJson(...), $page)]]);
        });
    }

    protected function cardHtml(int $reviewCount, bool $withContext = true, array $reviews = []): string
    {
        $state = [
            'stack' => [[
                'results' => [
                    'requestId' => '1789134670217643-872825800-addrs-upper-yp-23',
                    'items' => [[
                        'id' => '3855941798',
                        'title' => 'Термолэнд',
                        'ratingData' => [
                            'ratingValue' => 4.9,
                            'ratingCount' => 1915,
                            'reviewCount' => $reviewCount,
                        ],
                        'reviewResults' => [
                            'params' => ['page' => 1, 'totalPages' => 32],
                            'reviews' => array_map($this->reviewJson(...), $reviews),
                        ],
                    ]],
                ],
            ]],
        ];

        if ($withContext) {
            $state['config'] = [
                'csrfToken' => '2b67d575ac19c969b6a217c3aaaaaaaaaaaaaaaa:1789134670',
                'counters' => ['analytics' => [
                    'sessionId' => '1789134670183107-1607272622965325073-balancer-l7leveler-kubr-yp-klg-218-BAL',
                ]],
            ];
        }

        return '<script type="application/json" class="state-view">'
            .json_encode($state, JSON_UNESCAPED_UNICODE)
            .'</script>';
    }

    private function reviewJson(string $id): array
    {
        return [
            'reviewId' => $id,
            'author' => ['name' => "Автор {$id}"],
            'rating' => 5,
            'text' => "Отзыв {$id}",
            'updatedTime' => '2026-03-15T18:37:05.689Z',
        ];
    }
}
