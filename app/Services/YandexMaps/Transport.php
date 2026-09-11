<?php

namespace App\Services\YandexMaps;

use App\Services\YandexMaps\Exceptions\SourceBlocked;
use App\Services\YandexMaps\Proxy\ProxyPool;
use App\Settings\ParsingSettings;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Единственная точка, через которую приложение ходит к Яндексу.
 *
 * Здесь собрано всё, что относится к «как ходим», а не «что забираем»: прокси, паузы,
 * User-Agent, повторы и распознавание бана. Разбор ответов живёт отдельно — так при смене
 * способа выхода в сеть не придётся трогать логику парсинга, и наоборот.
 */
class Transport
{
    private ?float $lastRequestAt = null;

    private string $userAgent;

    /**
     * Куки живут весь проход: токен, который выдаёт страница карточки, привязан к сессии,
     * и без её кук внутренний эндпоинт отзывов этот токен не принимает.
     */
    private readonly CookieJar $cookies;

    private readonly ParsingSettings $settings;

    public function __construct(private readonly ProxyPool $pool, ?ParsingSettings $settings = null)
    {
        $this->settings = $settings ?? ParsingSettings::load();
        $this->cookies = new CookieJar;

        // User-Agent выбираем один раз на весь проход: браузер не меняет его между
        // страницами одного сайта, и парсер не должен.
        $this->userAgent = collect(config('parsing.user_agents'))->random();
    }

    public static function make(): self
    {
        $settings = ParsingSettings::load();

        return new self(
            new ProxyPool($settings->usesProxy() ? $settings->proxyPoolConfig() : []),
            $settings,
        );
    }

    /**
     * @throws SourceBlocked если источник закрылся, а сменить адрес нечем
     */
    public function get(string $url, array $query = [], array $headers = []): Response
    {
        $this->pause();

        $response = $this->send($url, $query, $headers);

        if (! $this->looksBlocked($response)) {
            return $response;
        }

        Log::warning('Яндекс ответил как на бота', [
            'url' => $url,
            'статус' => $response->status(),
        ]);

        // Второй заход имеет смысл только с другого адреса. Если менять не на что —
        // прекращаем, а не долбим источник повторами.
        if (! $this->pool->switch()) {
            throw new SourceBlocked("Источник закрыл доступ (HTTP {$response->status()})");
        }

        $this->pause();
        $response = $this->send($url, $query, $headers);

        if ($this->looksBlocked($response)) {
            throw new SourceBlocked("Источник закрыл доступ и после смены адреса (HTTP {$response->status()})");
        }

        return $response;
    }

    private function send(string $url, array $query, array $headers): Response
    {
        $this->lastRequestAt = microtime(true);

        try {
            return $this->request()->withHeaders($headers)->get($url, $query);
        } catch (ConnectionException $e) {
            // Сорванное соединение — это про сеть, а не про бан: пусть решает вызывающая сторона.
            throw new Exceptions\ParsingFailed("Не удалось связаться с Яндексом: {$e->getMessage()}", previous: $e);
        }
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout($this->settings->timeout())
            ->retry(config('parsing.retries'), 2000, throw: false)
            ->withHeaders([
                'User-Agent' => $this->userAgent,
                'Accept-Language' => 'ru-RU,ru;q=0.9',
            ]);

        $request->withOptions(['cookies' => $this->cookies]);

        $proxy = $this->pool->current();

        return $proxy ? $request->withOptions(['proxy' => $proxy->url]) : $request;
    }

    /**
     * Выдерживает случайную паузу между запросами, отсчитывая её от конца предыдущего.
     */
    private function pause(): void
    {
        $delay = random_int($this->settings->delayMin(), $this->settings->delayMax());

        if ($this->lastRequestAt !== null) {
            $elapsed = microtime(true) - $this->lastRequestAt;
            $delay = max(0, $delay - $elapsed);
        }

        if ($delay > 0) {
            usleep((int) ($delay * 1_000_000));
        }
    }

    private function looksBlocked(Response $response): bool
    {
        if (in_array($response->status(), [403, 429], true)) {
            return true;
        }

        // На капчу Яндекс отвечает обычной страницей с кодом 200, узнаём её по содержимому.
        return str_contains($response->body(), 'SmartCaptcha')
            || str_contains($response->body(), 'showcaptcha');
    }
}
