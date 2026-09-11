<?php

namespace App\Services\YandexMaps\Exceptions;

use RuntimeException;

/**
 * Общий предок всех сбоев парсинга. Отличаем их от прочих ошибок приложения, чтобы
 * задача в очереди могла показать пользователю человеческую причину, а не стектрейс.
 */
class ParsingFailed extends RuntimeException
{
    /**
     * Текст для интерфейса: без внутренних подробностей, но с понятным следующим шагом.
     */
    public function userMessage(): string
    {
        return 'Не удалось получить данные с Яндекс.Карт. Попробуйте повторить позже.';
    }
}
