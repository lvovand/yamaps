<?php

namespace App\Settings;

use App\Services\YandexMaps\Proxy\ProxyServer;

/**
 * Один прокси в том виде, в каком его заполняют в интерфейсе.
 *
 * Хранится по частям, а не строкой подключения: так проще и проверять поля, и прятать
 * пароль при выдаче наружу.
 */
final readonly class ProxySettings
{
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public ?string $username,
        public ?string $password,
        public string $type,
        public ?string $rotateUrl,
    ) {}

    public static function fromArray(array $data): self
    {
        // В .env прокси задаются одной строкой подключения — приводим её к тем же полям,
        // чтобы у настроек из окружения и из интерфейса был общий вид.
        if (isset($data['url'])) {
            $parts = parse_url($data['url']);

            $data += [
                'scheme' => $parts['scheme'] ?? 'http',
                'host' => $parts['host'] ?? '',
                'port' => $parts['port'] ?? 80,
                'username' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
                'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            ];
        }

        return new self(
            scheme: $data['scheme'] ?? 'http',
            host: $data['host'],
            port: (int) $data['port'],
            username: $data['username'] ?? null,
            password: $data['password'] ?? null,
            type: $data['type'] ?? ProxyServer::DATACENTER,
            rotateUrl: $data['rotate_url'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'scheme' => $this->scheme,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'type' => $this->type,
            'rotate_url' => $this->rotateUrl,
        ];
    }

    public function url(): string
    {
        $credentials = $this->username !== null && $this->username !== ''
            ? rawurlencode($this->username).':'.rawurlencode((string) $this->password).'@'
            : '';

        return "{$this->scheme}://{$credentials}{$this->host}:{$this->port}";
    }

    /**
     * Вид для интерфейса: пароль наружу не отдаём, только признак, что он задан.
     * Показать его можно отдельным запросом — по нажатию на «глаз».
     */
    public function toSafeArray(): array
    {
        return [
            'scheme' => $this->scheme,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'has_password' => $this->password !== null && $this->password !== '',
            'type' => $this->type,
            'rotate_url' => $this->rotateUrl,
        ];
    }
}
