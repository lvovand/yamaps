<?php

namespace Tests\Feature;

use App\Enums\FetchMode;
use App\Enums\ParseStatus;
use App\Jobs\ParseOrganizationReviews;
use App\Models\Organization;
use App\Models\Review;
use App\Services\YandexMaps\Exceptions\ParsingFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\YandexMapsFake;

class ParseOrganizationReviewsTest extends TestCase
{
    use RefreshDatabase, YandexMapsFake;

    protected function setUp(): void
    {
        parent::setUp();

        config(['parsing.delay.min' => 0, 'parsing.delay.max' => 0, 'parsing.retries' => 1]);
    }

    private function organization(FetchMode $mode = FetchMode::Recent): Organization
    {
        return Organization::create([
            'yandex_id' => '3855941798',
            'yandex_url' => 'https://yandex.ru/maps/org/3855941798/reviews/',
            'fetch_mode' => $mode,
            'status' => ParseStatus::Pending,
        ]);
    }

    public function test_сохраняет_отзывы_и_показатели(): void
    {
        $this->fakeYandex(reviewCount: 3, slices: [
            'by_time' => [['a', 'b'], ['c']],
        ]);

        $organization = $this->organization();
        (new ParseOrganizationReviews($organization))->handle();

        $organization->refresh();

        $this->assertSame(ParseStatus::Ready, $organization->status);
        $this->assertSame('Термолэнд', $organization->name);
        $this->assertSame(4.9, $organization->rating);
        $this->assertSame(1915, $organization->ratings_count);
        $this->assertSame(3, $organization->reviews_count);
        $this->assertNotNull($organization->parsed_at);
        $this->assertSame(3, $organization->reviews()->count());

        $review = Review::where('yandex_id', 'a')->first();
        $this->assertSame('Автор a', $review->author);
        $this->assertSame('2026-03-15', $review->published_at->toDateString());
    }

    public function test_максимальный_режим_добирает_отзывы_другими_срезами(): void
    {
        $this->fakeYandex(reviewCount: 4, slices: [
            'by_rating_desc' => [['a', 'b'], 'depth-limit'],
            'by_rating_asc' => [['c']],
            'by_time' => [['d']],
        ]);

        $organization = $this->organization(FetchMode::Maximum);
        (new ParseOrganizationReviews($organization))->handle();

        $this->assertSame(4, $organization->reviews()->count());
        $this->assertSame(4, $organization->refresh()->parsed_reviews);
    }

    public function test_повторный_проход_обновляет_отзывы_а_не_плодит_дубли(): void
    {
        $pass = 1;

        Http::fake(function ($request) use (&$pass) {
            if (! str_contains($request->url(), 'fetchReviews')) {
                return Http::response($this->cardHtml(reviewCount: 2));
            }

            $ids = $pass === 1 ? ['a', 'b'] : ['a', 'c'];
            $text = $pass === 1 ? 'Хорошо' : 'Передумал';

            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            if ((int) ($query['page'] ?? 1) > 1) {
                return Http::response(['data' => ['reviews' => []]]);
            }

            return Http::response(['data' => ['reviews' => array_map(fn ($id) => [
                'reviewId' => $id,
                'author' => ['name' => "Автор {$id}"],
                'rating' => $pass === 1 ? 5 : 3,
                'text' => $text,
            ], $ids)]]);
        });

        $organization = $this->organization();
        (new ParseOrganizationReviews($organization))->handle();

        $this->assertSame(2, $organization->reviews()->count());

        $pass = 2;
        (new ParseOrganizationReviews($organization))->handle();

        $this->assertSame(3, $organization->reviews()->count());
        $this->assertSame('Передумал', Review::where('yandex_id', 'a')->first()->text);
        $this->assertSame(3, Review::where('yandex_id', 'a')->first()->rating);
    }

    public function test_пишет_снимок_показателей_на_каждый_проход(): void
    {
        $this->fakeYandex(reviewCount: 1, slices: ['by_time' => [['a']]]);
        $organization = $this->organization();

        (new ParseOrganizationReviews($organization))->handle();
        (new ParseOrganizationReviews($organization))->handle();

        $this->assertSame(2, $organization->snapshots()->count());
        $this->assertSame(4.9, $organization->snapshots()->first()->rating);
    }

    public function test_при_бане_останавливается_с_понятным_сообщением(): void
    {
        Http::fake(['*' => Http::response('нельзя', 403)]);

        $organization = $this->organization();

        try {
            (new ParseOrganizationReviews($organization))->handle();
        } catch (\Throwable) {
            // Задача помечает себя проваленной — для теста важно состояние организации.
        }

        $organization->refresh();
        $this->assertSame(ParseStatus::Failed, $organization->status);
        $this->assertStringContainsString('ограничил доступ', $organization->error);
    }

    public function test_при_смене_формата_сообщает_что_парсер_сломан(): void
    {
        Http::fake(['*' => Http::response('<html>другая страница</html>')]);

        $organization = $this->organization();

        try {
            (new ParseOrganizationReviews($organization))->handle();
        } catch (\Throwable) {
        }

        $organization->refresh();
        $this->assertSame(ParseStatus::Failed, $organization->status);
        $this->assertStringContainsString('изменил формат', $organization->error);
    }

    public function test_сетевой_сбой_отдаётся_очереди_для_повтора(): void
    {
        Http::fake(fn () => throw new ConnectionException('таймаут'));

        $organization = $this->organization();

        $this->expectException(ParsingFailed::class);

        (new ParseOrganizationReviews($organization))->handle();
    }
}
