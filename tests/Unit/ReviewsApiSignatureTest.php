<?php

namespace Tests\Unit;

use App\Services\YandexMaps\ReviewsApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Подпись запроса восстановлена по реальным обращениям браузера. Образцы взяты из разных
 * сессий, поэтому тест заодно проверяет, что алгоритм не завязан на конкретные токены.
 */
class ReviewsApiSignatureTest extends TestCase
{
    #[DataProvider('realRequests')]
    public function test_повторяет_подпись_настоящего_запроса(array $params, int $expected): void
    {
        $this->assertSame($expected, ReviewsApi::sign($params));
    }

    public function test_подпись_зависит_от_параметров(): void
    {
        $params = self::realRequests()['страница 2'][0];
        $other = ['page' => '5'] + $params;

        $this->assertNotSame(ReviewsApi::sign($params), ReviewsApi::sign($other));
    }

    public static function realRequests(): array
    {
        $session = [
            'ajax' => '1',
            'businessId' => '3855941798',
            'csrfToken' => '3d65b0076377c1fad657b97be5c1b13a1dd12ec0:1789138560',
            'locale' => 'ru_US',
            'pageSize' => '50',
            'ranking' => 'by_relevance_org',
            'reqId' => '1789138560719822-3375799782-addrs-upper-yp-84',
            'sessionId' => '1789138560676830-4192227017845569558-balancer-l7leveler-kubr-yp-klg-96-BAL',
        ];

        return [
            'страница 2' => [['page' => '2'] + $session, 399649345],
            'страница 3' => [['page' => '3'] + $session, 2401060352],
            'другая сессия' => [[
                'ajax' => '1',
                'businessId' => '3855941798',
                'csrfToken' => '622d538425898152312cdb00699ef1cc5bff3ea4:1789134489',
                'locale' => 'ru_US',
                'page' => '2',
                'pageSize' => '50',
                'ranking' => 'by_relevance_org',
                'reqId' => '1789134489306285-1855994364-addrs-upper-yp-65',
                'sessionId' => '1789134489268643-2214948356832323445-balancer-l7leveler-kubr-yp-sas-73-BAL',
            ], 3809732817],
        ];
    }
}
