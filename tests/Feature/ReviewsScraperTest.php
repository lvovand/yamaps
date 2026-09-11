<?php

namespace Tests\Feature;

use App\Enums\FetchMode;
use App\Services\YandexMaps\Exceptions\SourceChanged;
use App\Services\YandexMaps\OrganizationLink;
use App\Services\YandexMaps\Proxy\ProxyPool;
use App\Services\YandexMaps\ReviewsApi;
use App\Services\YandexMaps\ReviewsScraper;
use App\Services\YandexMaps\Transport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\YandexMapsFake;

class ReviewsScraperTest extends TestCase
{
    use RefreshDatabase, YandexMapsFake;

    protected function setUp(): void
    {
        parent::setUp();

        config(['parsing.delay.min' => 0, 'parsing.delay.max' => 0, 'parsing.retries' => 1]);
    }

    private function scraper(): ReviewsScraper
    {
        $transport = new Transport(new ProxyPool);

        return new ReviewsScraper($transport, new ReviewsApi($transport));
    }

    private function link(): OrganizationLink
    {
        return OrganizationLink::parse('https://yandex.ru/maps/org/3855941798/reviews/');
    }

    private function collect(FetchMode $mode): array
    {
        $collected = [];
        $progress = [];

        $organization = $this->scraper()->scrape($this->link(), $mode, function ($reviews, $done, $total) use (&$collected, &$progress) {
            foreach ($reviews as $review) {
                $collected[] = $review->id;
            }
            $progress[] = "{$done}/{$total}";
        });

        return [$organization, $collected, $progress];
    }

    public function test_свежий_режим_идёт_одним_срезом(): void
    {
        $this->fakeYandex(reviewCount: 120, slices: [
            'by_time' => [['a1', 'a2'], ['a3']],
        ]);

        [$organization, $collected] = $this->collect(FetchMode::Recent);

        $this->assertSame('Термолэнд', $organization->name);
        $this->assertSame(['a1', 'a2', 'a3'], $collected);
    }

    public function test_максимальный_режим_объединяет_срезы_без_повторов(): void
    {
        // Срезы намеренно пересекаются: b2 встречается в обоих.
        $this->fakeYandex(reviewCount: 500, slices: [
            'by_rating_desc' => [['b1', 'b2']],
            'by_rating_asc' => [['b2', 'b3']],
            'by_time' => [['b4']],
        ]);

        [, $collected] = $this->collect(FetchMode::Maximum);

        $this->assertSame(['b1', 'b2', 'b3', 'b4'], $collected);
    }

    public function test_останавливается_когда_собрал_всё_обещанное(): void
    {
        // Карточка обещает 3 отзыва — после первого среза идти дальше незачем.
        $this->fakeYandex(reviewCount: 3, slices: [
            'by_rating_desc' => [['c1', 'c2', 'c3']],
            'by_rating_asc' => [['c4']],
        ]);

        [, $collected] = $this->collect(FetchMode::Maximum);

        $this->assertSame(['c1', 'c2', 'c3'], $collected);
    }

    public function test_упирается_в_потолок_среза_и_переходит_к_следующему(): void
    {
        // Первый срез обрывается ошибкой глубины — это штатная ситуация, не провал.
        $this->fakeYandex(reviewCount: 900, slices: [
            'by_rating_desc' => [['d1'], 'depth-limit'],
            'by_rating_asc' => [['d2']],
        ]);

        [, $collected] = $this->collect(FetchMode::Maximum);

        $this->assertSame(['d1', 'd2'], $collected);
    }

    public function test_сообщает_прогресс_по_мере_сбора(): void
    {
        $this->fakeYandex(reviewCount: 10, slices: [
            'by_time' => [['e1', 'e2'], ['e3']],
        ]);

        [, , $progress] = $this->collect(FetchMode::Recent);

        $this->assertSame(['2/10', '3/10'], $progress);
    }

    public function test_сломанная_страница_прекращает_выгрузку(): void
    {
        Http::fake(['*' => Http::response('<html>совсем другая страница</html>')]);

        $this->expectException(SourceChanged::class);

        $this->scraper()->scrape($this->link(), FetchMode::Recent, fn () => null);
    }

    public function test_без_токенов_отдаёт_хотя_бы_первый_экран(): void
    {
        // Страница без config: догружать нечем, но отзывы с самой страницы терять не стоит.
        Http::fake(['*' => Http::response($this->cardHtml(reviewCount: 120, withContext: false, reviews: ['f1', 'f2']))]);

        [, $collected] = $this->collect(FetchMode::Maximum);

        $this->assertSame(['f1', 'f2'], $collected);
    }
}
