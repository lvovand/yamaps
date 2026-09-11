<?php

namespace App\Settings;

use App\Models\Setting;

/**
 * Настройки парсера, которые можно менять из интерфейса.
 *
 * Значения по умолчанию берутся из config/parsing.php, то есть из окружения. База их
 * перекрывает — так на сервере не приходится править .env руками ради смены прокси,
 * а свежая установка работает сразу, ещё до того как в настройки кто-то заглянул.
 */
class ParsingSettings
{
    private const KEY = 'parsing';

    /** @var ProxySettings[]|null */
    private ?array $proxies = null;

    private array $values;

    public function __construct()
    {
        $stored = Setting::find(self::KEY)?->value ?? [];

        $this->values = $stored + [
            'transport' => config('parsing.transport'),
            'delay_min' => config('parsing.delay.min'),
            'delay_max' => config('parsing.delay.max'),
            'timeout' => config('parsing.timeout'),
            'proxies' => config('parsing.proxies'),
        ];
    }

    public static function load(): self
    {
        return new self;
    }

    public function transport(): string
    {
        return $this->values['transport'];
    }

    public function usesProxy(): bool
    {
        return $this->transport() === 'proxy' && $this->proxies() !== [];
    }

    public function delayMin(): int
    {
        return (int) $this->values['delay_min'];
    }

    public function delayMax(): int
    {
        return max($this->delayMin(), (int) $this->values['delay_max']);
    }

    public function timeout(): int
    {
        return (int) $this->values['timeout'];
    }

    /**
     * @return ProxySettings[]
     */
    public function proxies(): array
    {
        return $this->proxies ??= array_map(
            ProxySettings::fromArray(...),
            array_values($this->values['proxies'] ?? []),
        );
    }

    /**
     * Список прокси в том виде, в каком его ждёт пул: только адрес, тип и ссылка ротации.
     */
    public function proxyPoolConfig(): array
    {
        return array_map(fn (ProxySettings $proxy) => [
            'url' => $proxy->url(),
            'type' => $proxy->type,
            'rotate_url' => $proxy->rotateUrl,
        ], $this->proxies());
    }

    public function save(array $values): void
    {
        Setting::updateOrCreate(['key' => self::KEY], ['value' => $values]);
    }

    public function toSafeArray(): array
    {
        return [
            'transport' => $this->transport(),
            'delay_min' => $this->delayMin(),
            'delay_max' => $this->delayMax(),
            'timeout' => $this->timeout(),
            'proxies' => array_map(fn (ProxySettings $p) => $p->toSafeArray(), $this->proxies()),
        ];
    }
}
