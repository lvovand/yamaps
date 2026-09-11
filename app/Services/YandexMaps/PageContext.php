<?php

namespace App\Services\YandexMaps;

/**
 * Всё, что нужно взять со страницы карточки, чтобы дальше обращаться к её внутреннему эндпоинту.
 * Живёт ровно один проход: токен привязан ко времени, сессия — к загрузке страницы.
 */
final readonly class PageContext
{
    public function __construct(
        public string $businessId,
        public string $csrfToken,
        public string $sessionId,
        public string $requestId,
        public string $pageUrl,
    ) {}
}
