<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'author' => $this->author,
            // Профиль автора на Картах — полезно, чтобы оценить, кто пишет отзыв:
            // у Яндекса там видны остальные его отзывы и уровень «знатока города».
            'author_url' => $this->author_public_id
                ? "https://yandex.ru/maps/user/{$this->author_public_id}"
                : null,
            'rating' => $this->rating,
            'text' => $this->text,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
