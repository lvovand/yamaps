<?php

namespace Tests\Feature;

use App\Enums\FetchMode;
use App\Enums\ParseStatus;
use App\Jobs\CheckOrganizationCard;
use App\Jobs\ParseOrganizationReviews;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\YandexMapsFake;

class CheckOrganizationCardTest extends TestCase
{
    use RefreshDatabase, YandexMapsFake;

    protected function setUp(): void
    {
        parent::setUp();

        config(['parsing.delay.min' => 0, 'parsing.delay.max' => 0, 'parsing.retries' => 1]);
        Queue::fake();
    }

    private function organization(): Organization
    {
        return Organization::create([
            'yandex_id' => '3855941798',
            'yandex_url' => 'https://yandex.ru/maps/org/3855941798/reviews/',
            'status' => ParseStatus::Pending,
        ]);
    }

    public function test_небольшую_карточку_выгружает_без_вопросов(): void
    {
        Http::fake(['*' => Http::response($this->cardHtml(reviewCount: 120))]);

        $organization = $this->organization();
        (new CheckOrganizationCard($organization))->handle();

        $organization->refresh();

        $this->assertSame(ParseStatus::Pending, $organization->status);
        $this->assertSame(FetchMode::Recent, $organization->fetch_mode);
        $this->assertSame('Термолэнд', $organization->name);
        $this->assertSame(120, $organization->reviews_count);

        Queue::assertPushed(ParseOrganizationReviews::class);
    }

    public function test_у_крупной_карточки_спрашивает_режим(): void
    {
        Http::fake(['*' => Http::response($this->cardHtml(reviewCount: 1565))]);

        $organization = $this->organization();
        (new CheckOrganizationCard($organization))->handle();

        $organization->refresh();

        $this->assertSame(ParseStatus::AwaitingChoice, $organization->status);
        $this->assertSame(1565, $organization->reviews_count);

        // Пока пользователь не ответил, к Яндексу за отзывами не идём.
        Queue::assertNotPushed(ParseOrganizationReviews::class);
    }

    public function test_ровно_на_границе_выгружает_сам(): void
    {
        Http::fake(['*' => Http::response($this->cardHtml(reviewCount: 600))]);

        $organization = $this->organization();
        (new CheckOrganizationCard($organization))->handle();

        $this->assertSame(ParseStatus::Pending, $organization->refresh()->status);
        Queue::assertPushed(ParseOrganizationReviews::class);
    }

    public function test_уже_выгруженную_карточку_не_переспрашивает(): void
    {
        Http::fake(['*' => Http::response($this->cardHtml(reviewCount: 1565))]);

        $organization = $this->organization();
        // Режим выбирали раньше, выгрузка уже была — повторная проверка не должна
        // возвращать готовую организацию к вопросу.
        $organization->update([
            'fetch_mode' => FetchMode::Maximum,
            'status' => ParseStatus::Ready,
            'parsed_at' => now(),
        ]);

        (new CheckOrganizationCard($organization))->handle();

        $organization->refresh();

        $this->assertSame(ParseStatus::Pending, $organization->status);
        $this->assertSame(FetchMode::Maximum, $organization->fetch_mode);

        Queue::assertPushed(ParseOrganizationReviews::class);
    }

    public function test_проверка_делает_ровно_один_запрос(): void
    {
        Http::fake(['*' => Http::response($this->cardHtml(reviewCount: 1565))]);

        (new CheckOrganizationCard($this->organization()))->handle();

        Http::assertSentCount(1);
    }
}
