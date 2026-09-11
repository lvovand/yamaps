<?php

namespace App\Services\YandexMaps\Proxy;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ProxyServer
{
    public const DATACENTER = 'datacenter';

    public const MOBILE = 'mobile';

    public const RESIDENTIAL = 'residential';

    private bool $burned = false;

    public function __construct(
        public readonly string $url,
        public readonly string $type = self::DATACENTER,
        private readonly ?string $rotateUrl = null,
    ) {}

    public static function fromConfig(array $config): self
    {
        return new self(
            url: $config['url'],
            type: $config['type'] ?? self::DATACENTER,
            rotateUrl: $config['rotate_url'] ?? null,
        );
    }

    public function isUsable(): bool
    {
        return ! $this->burned;
    }

    /**
     * Пытается получить новый IP. Возвращает false, если адрес сменить нечем —
     * тогда вызывающий код должен взять следующий прокси из пула.
     */
    public function rotate(): bool
    {
        if ($this->type === self::RESIDENTIAL) {
            // Провайдер выдаёт новый адрес сам, достаточно следующего соединения.
            return true;
        }

        if ($this->type !== self::MOBILE || $this->rotateUrl === null) {
            $this->burned = true;

            return false;
        }

        try {
            Http::timeout(30)->get($this->rotateUrl)->throw();
        } catch (\Throwable $e) {
            Log::warning('Не удалось сменить IP на мобильном прокси', ['ошибка' => $e->getMessage()]);
            $this->burned = true;

            return false;
        }

        sleep(config('parsing.rotate_wait'));

        return true;
    }
}
