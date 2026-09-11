<?php

namespace App\Rules;

use App\Services\YandexMaps\OrganizationLink;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class YandexMapsLink implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Не похоже на ссылку карточки организации в Яндекс.Картах.');

            return;
        }

        // Короткую ссылку-шаринг здесь проверить нельзя: чтобы узнать организацию,
        // нужно сходить за редиректом. Этим займётся контроллер.
        if (OrganizationLink::isShort($value) || OrganizationLink::parse($value) !== null) {
            return;
        }

        $fail('Не похоже на ссылку карточки организации в Яндекс.Картах. Скопируйте адрес из адресной строки, когда карточка открыта, или воспользуйтесь кнопкой «Поделиться».');
    }
}
