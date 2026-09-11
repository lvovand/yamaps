<?php

namespace Tests\Unit;

use App\Services\YandexMaps\Exceptions\SourceChanged;
use App\Services\YandexMaps\ReviewsPage;
use PHPUnit\Framework\TestCase;

class ReviewsPageTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/reviews-page.html');
    }

    public function test_читает_показатели_организации(): void
    {
        $page = ReviewsPage::parse($this->fixture());

        $this->assertSame('3855941798', $page->organization->id);
        $this->assertSame('Термолэнд', $page->organization->name);
        $this->assertSame(4.9, $page->organization->rating);
        $this->assertSame(1915, $page->organization->ratingsCount);
        $this->assertSame(1565, $page->organization->reviewsCount);
    }

    public function test_достаёт_токены_для_догрузки_отзывов(): void
    {
        $page = ReviewsPage::parse($this->fixture(), 'https://yandex.ru/maps/org/3855941798/reviews/');

        $this->assertNotNull($page->context);
        $this->assertSame('3855941798', $page->context->businessId);
        $this->assertStringStartsWith('2b67d575', $page->context->csrfToken);
        $this->assertStringEndsWith('-BAL', $page->context->sessionId);
        $this->assertStringContainsString('addrs-upper', $page->context->requestId);
    }

    public function test_без_токенов_показатели_всё_равно_читаются(): void
    {
        $json = '{"stack":[{"results":{"items":[{"id":"1","title":"Т","ratingData":{"ratingValue":5,"ratingCount":1,"reviewCount":1},"reviewResults":{"params":{"page":1,"totalPages":1},"reviews":[]}}]}}]}';

        $page = ReviewsPage::parse('<script class="state-view">'.$json.'</script>');

        $this->assertNull($page->context);
        $this->assertSame('Т', $page->organization->name);
    }

    public function test_читает_пагинацию(): void
    {
        $page = ReviewsPage::parse($this->fixture());

        $this->assertSame(1, $page->page);
        $this->assertSame(32, $page->totalPages);
    }

    public function test_читает_отзывы(): void
    {
        $page = ReviewsPage::parse($this->fixture());

        $this->assertCount(3, $page->reviews);

        $first = $page->reviews[0];
        $this->assertSame('HO8jmZr1gccMlyFCDrKvw6kdkoPtkezK-', $first->id);
        $this->assertSame('Альбина Троян', $first->author);
        $this->assertSame(5, $first->rating);
        $this->assertNotEmpty($first->text);
        $this->assertSame('2026-03-15', $first->publishedAt->toDateString());
    }

    public function test_пропускает_отзыв_без_идентификатора(): void
    {
        // Отзыв, который нечем опознать, попал бы в базу дублем при следующем проходе.
        $broken = json_encode([
            'stack' => [[
                'results' => ['items' => [[
                    'id' => '1',
                    'title' => 'Тест',
                    'ratingData' => ['ratingValue' => 4.5, 'ratingCount' => 10, 'reviewCount' => 2],
                    'reviewResults' => [
                        'params' => ['page' => 1, 'totalPages' => 1],
                        'reviews' => [
                            ['author' => ['name' => 'Без идентификатора'], 'rating' => 5],
                            ['reviewId' => 'ok', 'author' => ['name' => 'Нормальный'], 'rating' => 4],
                        ],
                    ],
                ]]],
            ]],
        ], JSON_UNESCAPED_UNICODE);

        $page = ReviewsPage::parse('<script class="state-view">'.$broken.'</script>');

        $this->assertCount(1, $page->reviews);
        $this->assertSame('ok', $page->reviews[0]->id);
    }

    public function test_отзыв_без_текста_и_даты_не_ломает_разбор(): void
    {
        // Оценку можно поставить, ничего не написав — такие записи встречаются.
        $json = json_encode([
            'stack' => [[
                'results' => ['items' => [[
                    'id' => '1',
                    'title' => 'Тест',
                    'ratingData' => ['ratingValue' => 4.5, 'ratingCount' => 10, 'reviewCount' => 2],
                    'reviewResults' => [
                        'params' => ['page' => 1, 'totalPages' => 1],
                        'reviews' => [['reviewId' => 'a', 'rating' => 5]],
                    ],
                ]]],
            ]],
        ], JSON_UNESCAPED_UNICODE);

        $page = ReviewsPage::parse('<script class="state-view">'.$json.'</script>');

        $this->assertNull($page->reviews[0]->text);
        $this->assertNull($page->reviews[0]->author);
        $this->assertNull($page->reviews[0]->publishedAt);
    }

    public function test_замечает_что_блок_состояния_пропал(): void
    {
        $this->expectException(SourceChanged::class);

        ReviewsPage::parse('<html><body>Обычная страница без состояния</body></html>');
    }

    public function test_замечает_что_структура_состояния_изменилась(): void
    {
        $this->expectException(SourceChanged::class);

        ReviewsPage::parse('<script class="state-view">{"stack":[{"results":{"items":[]}}]}</script>');
    }

    public function test_замечает_что_пропала_пагинация(): void
    {
        $json = json_encode([
            'stack' => [['results' => ['items' => [[
                'id' => '1',
                'title' => 'Тест',
                'reviewResults' => ['reviews' => [], 'params' => ['offset' => 0]],
            ]]]]],
        ]);

        $this->expectException(SourceChanged::class);

        ReviewsPage::parse('<script class="state-view">'.$json.'</script>');
    }

    public function test_карточку_без_блока_отзывов_разбирает_но_помечает(): void
    {
        // Так Яндекс отвечает на страницы за пределом своей выдачи: карточка есть, отзывов нет.
        $json = json_encode([
            'stack' => [['results' => ['items' => [[
                'id' => '1',
                'title' => 'Тест',
                'ratingData' => ['ratingValue' => 4.9, 'ratingCount' => 100, 'reviewCount' => 80],
            ]]]]],
        ]);

        $page = ReviewsPage::parse('<script class="state-view">'.$json.'</script>');

        $this->assertFalse($page->hasReviewsBlock);
        $this->assertSame([], $page->reviews);
        $this->assertSame('Тест', $page->organization->name);
    }

    public function test_переживает_смену_порядка_атрибутов_у_тега(): void
    {
        $json = '{"stack":[{"results":{"items":[{"id":"1","title":"Т","ratingData":{"ratingValue":5,"ratingCount":1,"reviewCount":1},"reviewResults":{"params":{"page":1,"totalPages":1},"reviews":[]}}]}}]}';

        $page = ReviewsPage::parse('<script type="application/json" class="state-view">'.$json.'</script>');

        $this->assertSame(1, $page->totalPages);
    }
}
