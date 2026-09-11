<?php

namespace Tests\Feature;

use App\Enums\ParseStatus;
use App\Jobs\CheckOrganizationCard;
use App\Jobs\ParseOrganizationReviews;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->actingAs(User::factory()->create());
    }

    public function test_принимает_ссылку_и_ставит_проверку_карточки(): void
    {
        $response = $this->postJson('/api/organizations', [
            'url' => 'https://yandex.ru/maps/org/termoland/3855941798/reviews/',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('organizations', [
            'yandex_id' => '3855941798',
            'yandex_url' => 'https://yandex.ru/maps/org/3855941798/reviews/',
        ]);

        // Сразу за отзывами не идём: сначала смотрим, сколько их вообще.
        Queue::assertPushed(CheckOrganizationCard::class);
        Queue::assertNotPushed(ParseOrganizationReviews::class);
    }

    public function test_запускает_выгрузку_выбранным_режимом(): void
    {
        $organization = Organization::create([
            'yandex_id' => '1',
            'yandex_url' => 'https://yandex.ru/maps/org/1/reviews/',
            'status' => ParseStatus::AwaitingChoice,
            'reviews_count' => 1565,
        ]);

        $this->postJson("/api/organizations/{$organization->id}/start", ['fetch_mode' => 'maximum'])
            ->assertOk()
            ->assertJsonPath('data.fetch_mode', 'maximum')
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(ParseOrganizationReviews::class);
    }

    public function test_не_принимает_несуществующий_режим(): void
    {
        $organization = Organization::create([
            'yandex_id' => '1',
            'yandex_url' => 'https://yandex.ru/maps/org/1/reviews/',
            'status' => ParseStatus::AwaitingChoice,
        ]);

        $this->postJson("/api/organizations/{$organization->id}/start", ['fetch_mode' => 'всё-всё-всё'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fetch_mode');

        Queue::assertNotPushed(ParseOrganizationReviews::class);
    }

    public function test_ту_же_организацию_повторно_не_дублирует(): void
    {
        $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/termoland/3855941798/'])
            ->assertCreated();

        // Та же карточка, но ссылка скопирована с карты — вид другой, организация та же.
        $this->postJson('/api/organizations', [
            'url' => 'https://yandex.com/maps/2/saint-petersburg/?mode=poi&poi%5Buri%5D=ymapsbm1%3A%2F%2Forg%3Foid%3D3855941798',
        ])->assertCreated();

        $this->assertSame(1, Organization::count());
    }

    public function test_разворачивает_короткую_ссылку_шаринга(): void
    {
        // Так делятся карточкой из приложения Карт: организация появляется только в редиректе.
        Http::fake(['yandex.ru/maps/-/*' => Http::response('', 302, [
            'Location' => '/maps/2/saint-petersburg/?mode=poi&poi%5Buri%5D=ymapsbm1%3A%2F%2Forg%3Foid%3D1395912980&utm_source=share',
        ])]);

        $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/-/CThpBKJS'])
            ->assertCreated();

        $this->assertDatabaseHas('organizations', [
            'yandex_id' => '1395912980',
            'yandex_url' => 'https://yandex.ru/maps/org/1395912980/reviews/',
        ]);
    }

    public function test_сообщает_если_короткая_ссылка_никуда_не_ведёт(): void
    {
        Http::fake(['yandex.ru/maps/-/*' => Http::response('', 404)]);

        $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/-/СЛОМАНА'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');

        $this->assertSame(0, Organization::count());
    }

    public function test_отклоняет_непонятную_ссылку(): void
    {
        $this->postJson('/api/organizations', ['url' => 'https://2gis.ru/spb/firm/123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');

        $this->assertSame(0, Organization::count());
    }

    public function test_отдаёт_статус_и_показатели(): void
    {
        $organization = Organization::create([
            'yandex_id' => '1',
            'yandex_url' => 'https://yandex.ru/maps/org/1/reviews/',
            'name' => 'Термолэнд',
            'rating' => 4.9,
            'ratings_count' => 1915,
            'reviews_count' => 1565,
            'status' => ParseStatus::Ready,
            'parsed_reviews' => 1565,
        ]);

        $this->getJson("/api/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Термолэнд')
            ->assertJsonPath('data.rating', 4.9)
            ->assertJsonPath('data.ratings_count', 1915)
            ->assertJsonPath('data.reviews_count', 1565)
            ->assertJsonPath('data.status_label', 'Готово');
    }

    public function test_отзывы_отдаются_по_пятьдесят_свежими_вперёд(): void
    {
        $organization = Organization::create([
            'yandex_id' => '1',
            'yandex_url' => 'https://yandex.ru/maps/org/1/reviews/',
            'status' => ParseStatus::Ready,
        ]);

        foreach (range(1, 120) as $i) {
            $organization->reviews()->create([
                'yandex_id' => "r{$i}",
                'author' => "Автор {$i}",
                'rating' => 5,
                'text' => "Отзыв {$i}",
                'published_at' => now()->subDays(120 - $i),
            ]);
        }

        $first = $this->getJson("/api/organizations/{$organization->id}/reviews")->assertOk();
        $first->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.total', 120)
            ->assertJsonPath('data.0.author', 'Автор 120');

        $this->getJson("/api/organizations/{$organization->id}/reviews?page=3")
            ->assertOk()
            ->assertJsonCount(20, 'data');
    }

    public function test_без_авторизации_данные_не_отдаются(): void
    {
        app()['auth']->forgetGuards();

        $this->getJson('/api/organizations')->assertUnauthorized();
        $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/1/'])->assertUnauthorized();
    }
}
