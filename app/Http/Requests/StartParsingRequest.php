<?php

namespace App\Http\Requests;

use App\Enums\FetchMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartParsingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fetch_mode' => ['required', Rule::enum(FetchMode::class)],
        ];
    }

    public function fetchMode(): FetchMode
    {
        return FetchMode::from($this->string('fetch_mode')->toString());
    }
}
