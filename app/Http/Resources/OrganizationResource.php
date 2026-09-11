<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->yandex_url,
            'rating' => $this->rating,
            'ratings_count' => $this->ratings_count,
            'reviews_count' => $this->reviews_count,
            // Сколько отзывов реально лежит у нас. Из-за потолка выдачи это число почти всегда
            // меньше, чем у Яндекса, и пользователь должен видеть разницу, а не гадать.
            'collected_reviews' => $this->collected_reviews,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'fetch_mode' => $this->fetch_mode->value,
            'fetch_mode_label' => $this->fetch_mode->label(),
            'parsed_reviews' => $this->parsed_reviews,
            'parsed_at' => $this->parsed_at?->toIso8601String(),
            'error' => $this->error,
            // Сколько отзывов источник отдаёт за одну выдачу — интерфейсу это нужно,
            // чтобы объяснить пользователю, почему у него спрашивают про режим.
            'slice_limit' => (int) config('parsing.slice_limit'),
        ];
    }
}
