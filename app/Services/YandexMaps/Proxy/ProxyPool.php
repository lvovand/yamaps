<?php

namespace App\Services\YandexMaps\Proxy;

/**
 * Список прокси, по которому парсер идёт сверху вниз.
 *
 * Пул намеренно не перебирает адреса на каждом запросе: отзывы одной организации
 * выгружаются одной сессией, и прыжки между IP посреди пагинации выглядят подозрительнее,
 * чем ровная работа с одного адреса. Смена происходит только когда текущий адрес забанили.
 */
class ProxyPool
{
    /** @var ProxyServer[] */
    private array $servers;

    private int $current = 0;

    public function __construct(array $config = [])
    {
        $this->servers = array_map(ProxyServer::fromConfig(...), $config);
    }

    public function isEmpty(): bool
    {
        return $this->servers === [];
    }

    public function current(): ?ProxyServer
    {
        return $this->servers[$this->current] ?? null;
    }

    /**
     * Сменить адрес после бана: сначала пробуем перевыпустить IP у текущего прокси,
     * и только если это невозможно — переходим к следующему в списке.
     *
     * @return bool удалось ли найти, чем продолжать работу
     */
    public function switch(): bool
    {
        $server = $this->current();

        if ($server === null) {
            return false;
        }

        if ($server->rotate()) {
            return true;
        }

        while (++$this->current < count($this->servers)) {
            if ($this->current()->isUsable()) {
                return true;
            }
        }

        return false;
    }
}
