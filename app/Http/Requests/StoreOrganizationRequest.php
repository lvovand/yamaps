<?php

namespace App\Http\Requests;

use App\Rules\YandexMapsLink;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', new YandexMapsLink],
        ];
    }
}
