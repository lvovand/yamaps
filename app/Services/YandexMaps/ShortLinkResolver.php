<?php

namespace App\Services\YandexMaps;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Разворачивает короткие ссылки, которыми делятся из приложения Карт.
 *
 * В самой ссылке организации нет — только её код, поэтому единственный способ узнать,
 * куда она ведёт, это сходить за редиректом. Одного запроса хватает: Яндекс отвечает
 * заголовком Location с полным адресом, дальше работает обычный разбор.
 */
class ShortLinkResolver
{
    public function resolve(string $url): ?OrganizationLink
    {
        try {
            $response = Http::withoutRedirecting()
                ->timeout(15)
                ->withHeaders([
                    'User-Agent' => collect(config('parsing.user_agents'))->random(),
                    'Accept-Language' => 'ru-RU,ru;q=0.9',
                ])
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('Не удалось развернуть короткую ссылку', ['ссылка' => $url, 'ошибка' => $e->getMessage()]);

            return null;
        }

        $location = $response->header('Location');

        if ($location === '') {
            return null;
        }

        // Location приходит относительным, а разбор ссылки ждёт полный адрес.
        if (! str_starts_with($location, 'http')) {
            $host = parse_url($url, PHP_URL_HOST);
            $location = "https://{$host}{$location}";
        }

        return OrganizationLink::parse($location);
    }
}
