<?php

namespace App\Enums;

enum ParseStatus: string
{
    case Pending = 'pending';
    case Checking = 'checking';
    case AwaitingChoice = 'awaiting_choice';
    case Parsing = 'parsing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'В очереди',
            self::Checking => 'Проверяем карточку',
            self::AwaitingChoice => 'Нужно выбрать режим',
            self::Parsing => 'Загружаем отзывы',
            self::Ready => 'Готово',
            self::Failed => 'Ошибка',
        };
    }
}
