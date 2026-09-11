<?php

namespace App\Http\Requests;

use App\Services\YandexMaps\Proxy\ProxyServer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateParsingSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'transport' => ['required', Rule::in(['direct', 'proxy'])],
            // Нижняя граница паузы намеренно не даёт поставить ноль: запросы без задержки —
            // самый быстрый способ получить бан на адрес, с которого работает и сам сайт.
            'delay_min' => ['required', 'integer', 'min:1', 'max:60'],
            'delay_max' => ['required', 'integer', 'min:1', 'max:120', 'gte:delay_min'],
            'timeout' => ['required', 'integer', 'min:5', 'max:120'],

            'proxies' => ['array', 'max:20'],
            'proxies.*.scheme' => ['required', Rule::in(['http', 'https', 'socks5', 'socks5h'])],
            'proxies.*.host' => ['required', 'string', 'max:255'],
            'proxies.*.port' => ['required', 'integer', 'min:1', 'max:65535'],
            'proxies.*.username' => ['nullable', 'string', 'max:255'],
            'proxies.*.password' => ['nullable', 'string', 'max:255'],
            'proxies.*.type' => ['required', Rule::in([
                ProxyServer::DATACENTER,
                ProxyServer::MOBILE,
                ProxyServer::RESIDENTIAL,
            ])],
            // Ссылка ротации нужна только мобильным: у остальных типов адрес так не меняется.
            'proxies.*.rotate_url' => ['nullable', 'url', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'delay_max.gte' => 'Верхняя граница паузы не может быть меньше нижней.',
            'delay_min.min' => 'Пауза между запросами должна быть хотя бы секунду.',
        ];
    }
}
