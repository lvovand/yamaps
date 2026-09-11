<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateParsingSettingsRequest;
use App\Settings\ParsingSettings;
use App\Settings\ProxySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ParsingSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => ParsingSettings::load()->toSafeArray()]);
    }

    public function update(UpdateParsingSettingsRequest $request): JsonResponse
    {
        $settings = ParsingSettings::load();
        $existing = $settings->proxies();

        $proxies = [];

        foreach ($request->input('proxies', []) as $index => $proxy) {
            // Пароль наружу не отдаётся, поэтому форма присылает его только если его меняли.
            // Пустое поле означает «оставить как было», а не «стереть».
            if (($proxy['password'] ?? '') === '' || $proxy['password'] === null) {
                $proxy['password'] = $this->passwordOf($existing, $index, $proxy);
            }

            $proxies[] = ProxySettings::fromArray($proxy)->toArray();
        }

        $settings->save([
            'transport' => $request->string('transport')->toString(),
            'delay_min' => $request->integer('delay_min'),
            'delay_max' => $request->integer('delay_max'),
            'timeout' => $request->integer('timeout'),
            'proxies' => $proxies,
        ]);

        return response()->json(['data' => ParsingSettings::load()->toSafeArray()]);
    }

    /**
     * Показать сохранённый пароль прокси — по кнопке «глаз» в интерфейсе.
     */
    public function revealPassword(int $index): JsonResponse
    {
        $proxy = ParsingSettings::load()->proxies()[$index] ?? null;

        abort_if($proxy === null, 404);

        return response()->json(['password' => $proxy->password]);
    }

    /**
     * Проверка прокси: один запрос к сервису, который просто возвращает наш адрес.
     * К Яндексу при проверке не ходим — незачем тратить его терпение на диагностику.
     */
    public function checkProxy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scheme' => ['required', 'string'],
            'host' => ['required', 'string'],
            'port' => ['required', 'integer'],
            'username' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
            'index' => ['nullable', 'integer'],
        ]);

        if (($data['password'] ?? '') === '' && isset($data['index'])) {
            $saved = ParsingSettings::load()->proxies()[$data['index']] ?? null;
            $data['password'] = $saved?->password;
        }

        $proxy = ProxySettings::fromArray($data + ['type' => 'datacenter']);

        try {
            $response = Http::timeout(20)
                ->withOptions(['proxy' => $proxy->url()])
                ->get('https://api.ipify.org', ['format' => 'json']);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Прокси не отвечает: '.$e->getMessage(),
            ]);
        }

        if ($response->failed()) {
            return response()->json([
                'ok' => false,
                'message' => "Прокси ответил с ошибкой (HTTP {$response->status()}).",
            ]);
        }

        $ip = $response->json('ip');

        return response()->json([
            'ok' => true,
            'ip' => $ip,
            'message' => "Прокси работает, внешний адрес — {$ip}.",
        ]);
    }

    /**
     * @param  ProxySettings[]  $existing
     */
    private function passwordOf(array $existing, int $index, array $proxy): ?string
    {
        $saved = $existing[$index] ?? null;

        // Прокси в списке могли переставить, поэтому сверяем ещё и адрес: пароль от другого
        // сервера здесь был бы хуже, чем его отсутствие.
        return $saved !== null && $saved->host === ($proxy['host'] ?? null)
            ? $saved->password
            : null;
    }
}
