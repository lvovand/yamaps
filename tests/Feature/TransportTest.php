<?php

namespace Tests\Feature;

use App\Services\YandexMaps\Exceptions\SourceBlocked;
use App\Services\YandexMaps\Proxy\ProxyPool;
use App\Services\YandexMaps\Proxy\ProxyServer;
use App\Services\YandexMaps\Transport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TransportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Паузы между запросами в тестах ни к чему, иначе набегут минуты ожидания.
        config([
            'parsing.delay.min' => 0,
            'parsing.delay.max' => 0,
            'parsing.rotate_wait' => 0,
            'parsing.retries' => 1,
        ]);
    }

    public function test_возвращает_ответ_источника(): void
    {
        Http::fake(['*' => Http::response('всё хорошо')]);

        $response = (new Transport(new ProxyPool))->get('https://yandex.ru/maps/org/1/reviews/');

        $this->assertSame('всё хорошо', $response->body());
    }

    public function test_подставляет_прокси_в_запрос(): void
    {
        Http::fake(['*' => Http::response('ок')]);

        $pool = new ProxyPool([
            ['url' => 'http://user:pass@10.0.0.1:8000', 'type' => ProxyServer::DATACENTER],
        ]);

        (new Transport($pool))->get('https://yandex.ru/maps/org/1/reviews/');

        Http::assertSent(fn ($request) => $request->toPsrRequest()->getUri()->getHost() === 'yandex.ru');
        $this->assertSame('http://user:pass@10.0.0.1:8000', $pool->current()->url);
    }

    public function test_без_прокси_бан_сразу_останавливает_работу(): void
    {
        Http::fake(['*' => Http::response('нельзя', 403)]);

        $this->expectException(SourceBlocked::class);

        (new Transport(new ProxyPool))->get('https://yandex.ru/maps/org/1/reviews/');

        // Повторов быть не должно — второй запрос на забаненный адрес только усугубляет.
        Http::assertSentCount(1);
    }

    public function test_капчу_узнаёт_по_телу_ответа(): void
    {
        Http::fake(['*' => Http::response('<div id="SmartCaptcha"></div>', 200)]);

        $this->expectException(SourceBlocked::class);

        (new Transport(new ProxyPool))->get('https://yandex.ru/maps/org/1/reviews/');
    }

    public function test_после_бана_берёт_следующий_прокси_из_списка(): void
    {
        $attempt = 0;

        Http::fake(function () use (&$attempt) {
            $attempt++;

            return $attempt === 1
                ? Http::response('нельзя', 429)
                : Http::response('получилось');
        });

        $pool = new ProxyPool([
            ['url' => 'http://10.0.0.1:8000', 'type' => ProxyServer::DATACENTER],
            ['url' => 'http://10.0.0.2:8000', 'type' => ProxyServer::DATACENTER],
        ]);

        $response = (new Transport($pool))->get('https://yandex.ru/maps/org/1/reviews/');

        $this->assertSame('получилось', $response->body());
        $this->assertSame('http://10.0.0.2:8000', $pool->current()->url);
    }

    public function test_мобильный_прокси_меняет_ip_вместо_смены_прокси(): void
    {
        $attempt = 0;

        Http::fake([
            'rotate.example/*' => Http::response('IP changed'),
            '*' => function () use (&$attempt) {
                $attempt++;

                return $attempt === 1
                    ? Http::response('нельзя', 403)
                    : Http::response('получилось');
            },
        ]);

        $pool = new ProxyPool([
            [
                'url' => 'http://10.0.0.1:8000',
                'type' => ProxyServer::MOBILE,
                'rotate_url' => 'https://rotate.example/change',
            ],
        ]);

        $response = (new Transport($pool))->get('https://yandex.ru/maps/org/1/reviews/');

        $this->assertSame('получилось', $response->body());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'rotate.example'));
        // Прокси остался прежним — сменился только адрес за ним.
        $this->assertSame('http://10.0.0.1:8000', $pool->current()->url);
    }

    public function test_если_менять_адрес_не_на_что_работа_прекращается(): void
    {
        Http::fake(['*' => Http::response('нельзя', 403)]);

        $pool = new ProxyPool([
            ['url' => 'http://10.0.0.1:8000', 'type' => ProxyServer::DATACENTER],
        ]);

        $this->expectException(SourceBlocked::class);

        (new Transport($pool))->get('https://yandex.ru/maps/org/1/reviews/');
    }
}
