<?php

namespace App\Services\YandexMaps\Data;

use Carbon\CarbonImmutable;

final readonly class ReviewData
{
    public function __construct(
        public string $id,
        public ?string $author,
        public ?string $authorPublicId,
        public ?int $rating,
        public ?string $text,
        public ?CarbonImmutable $publishedAt,
    ) {}

    public function toDatabaseRow(int $organizationId): array
    {
        return [
            'organization_id' => $organizationId,
            'yandex_id' => $this->id,
            'author' => $this->author,
            'author_public_id' => $this->authorPublicId,
            'rating' => $this->rating,
            'text' => $this->text,
            'published_at' => $this->publishedAt?->toDateTimeString(),
        ];
    }
}
