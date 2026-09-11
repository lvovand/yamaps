<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Settings\ParsingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParsingSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'transport' => 'proxy',
            'delay_min' => 5,
            'delay_max' => 7,
            'timeout' => 20,
            'proxies' => [[
                'scheme' => 'http',
                'host' => '10.0.0.1',
                'port' => 8000,
                'username' => 'user',
                'password' => 'секрет',
                'type' => 'mobile',
                'rotate_url' => 'https://rotate.example/change',
            ]],
        ], $overrides);
    }

    public function test_отдаёт_значения_по_умолчанию_из_конфига(): void
    {
        $this->getJson('/api/settings/parsing')
            ->assertOk()
            ->assertJsonPath('data.transport', 'direct')
            ->assertJsonPath('data.delay_min', 5)
            ->assertJsonPath('data.proxies', []);
    }

    public function test_сохраняет_настройки(): void
    {
        $this->putJson('/api/settings/parsing', $this->payload())->assertOk();

        $settings = ParsingSettings::load();

        $this->assertTrue($settings->usesProxy());
        $this->assertSame('http://user:%D1%81%D0%B5%D0%BA%D1%80%D0%B5%D1%82@10.0.0.1:8000', $settings->proxies()[0]->url());
        $this->assertSame('mobile', $settings->proxies()[0]->type);
    }

    public function test_пароль_наружу_не_отдаётся(): void
    {
        $this->putJson('/api/settings/parsing', $this->payload())->assertOk();

        $response = $this->getJson('/api/settings/parsing')->assertOk();

        $response->assertJsonPath('data.proxies.0.has_password', true);
        $this->assertArrayNotHasKey('password', $response->json('data.proxies.0'));
        $this->assertStringNotContainsString('секрет', $response->getContent());
    }

    public function test_пароль_показывается_по_отдельному_запросу(): void
    {
        $this->putJson('/api/settings/parsing', $this->payload())->assertOk();

        $this->getJson('/api/settings/parsing/proxies/0/password')
            ->assertOk()
            ->assertJsonPath('password', 'секрет');
    }

    public function test_пустое_поле_пароля_не_стирает_сохранённый(): void
    {
        $this->putJson('/api/settings/parsing', $this->payload())->assertOk();

        // Форма присылает пароль пустым, когда его не трогали.
        $this->putJson('/api/settings/parsing', $this->payload(['proxies' => [['password' => '']]]))
            ->assertOk();

        $this->assertSame('секрет', ParsingSettings::load()->proxies()[0]->password);
    }

    public function test_пароль_не_переезжает_на_другой_адрес(): void
    {
        $this->putJson('/api/settings/parsing', $this->payload())->assertOk();

        // Тот же индекс, но другой сервер — старый пароль сюда подставлять нельзя.
        $this->putJson('/api/settings/parsing', $this->payload([
            'proxies' => [['host' => '10.0.0.99', 'password' => '']],
        ]))->assertOk();

        $this->assertNull(ParsingSettings::load()->proxies()[0]->password);
    }

    public function test_не_даёт_убрать_паузу_между_запросами(): void
    {
        $this->putJson('/api/settings/parsing', $this->payload(['delay_min' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('delay_min');
    }

    public function test_не_даёт_перевернуть_промежуток_пауз(): void
    {
        $this->putJson('/api/settings/parsing', $this->payload(['delay_min' => 10, 'delay_max' => 3]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('delay_max');
    }

    public function test_проверка_прокси_сообщает_внешний_адрес(): void
    {
        Http::fake(['api.ipify.org*' => Http::response(['ip' => '203.0.113.7'])]);

        $this->postJson('/api/settings/parsing/check-proxy', [
            'scheme' => 'http', 'host' => '10.0.0.1', 'port' => 8000,
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ip', '203.0.113.7');
    }

    public function test_проверка_прокси_сообщает_о_недоступности(): void
    {
        Http::fake(fn () => throw new ConnectionException('нет связи'));

        $this->postJson('/api/settings/parsing/check-proxy', [
            'scheme' => 'http', 'host' => '10.0.0.1', 'port' => 8000,
        ])
            ->assertOk()
            ->assertJsonPath('ok', false);
    }

    public function test_настройки_закрыты_от_посторонних(): void
    {
        app()['auth']->forgetGuards();

        $this->getJson('/api/settings/parsing')->assertUnauthorized();
        $this->putJson('/api/settings/parsing', $this->payload())->assertUnauthorized();
        $this->getJson('/api/settings/parsing/proxies/0/password')->assertUnauthorized();
    }

    public function test_настройки_из_базы_перекрывают_окружение(): void
    {
        config(['parsing.delay.min' => 2, 'parsing.delay.max' => 3]);
        Setting::create(['key' => 'parsing', 'value' => ['delay_min' => 9, 'delay_max' => 11]]);

        $settings = ParsingSettings::load();

        $this->assertSame(9, $settings->delayMin());
        $this->assertSame(11, $settings->delayMax());
        // То, чего в базе нет, по-прежнему берётся из конфига.
        $this->assertSame('direct', $settings->transport());
    }
}
