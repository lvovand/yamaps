<?php

namespace App\Services\YandexMaps\Data;

/**
 * Показатели карточки на момент разбора страницы.
 *
 * Число оценок и число отзывов — разные величины: оценку можно поставить, ничего не написав,
 * поэтому оценок всегда больше. ТЗ требует показывать обе отдельно.
 */
final readonly class OrganizationData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?float $rating,
        public ?int $ratingsCount,
        public ?int $reviewsCount,
    ) {}
}
