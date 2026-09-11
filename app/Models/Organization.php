<?php

namespace App\Models;

use App\Enums\FetchMode;
use App\Enums\ParseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = [
        'yandex_id',
        'yandex_url',
        'fetch_mode',
        'name',
        'rating',
        'ratings_count',
        'reviews_count',
        'status',
        'parsed_at',
        'parsed_reviews',
        'error',
    ];

    // Умолчание из миграции не попадает в свежесозданную модель, а интерфейсу режим нужен сразу.
    protected $attributes = [
        'fetch_mode' => FetchMode::Recent->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => ParseStatus::class,
            'fetch_mode' => FetchMode::class,
            'rating' => 'float',
            'parsed_at' => 'datetime',
        ];
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(OrganizationSnapshot::class);
    }

    /**
     * Фиксирует показатели после удачного прохода парсера.
     * Снимок пишем всегда, даже если ничего не изменилось: по нему видно, что проверка была.
     */
    public function recordSnapshot(): void
    {
        $this->snapshots()->create([
            'rating' => $this->rating,
            'ratings_count' => $this->ratings_count,
            'reviews_count' => $this->reviews_count,
            'created_at' => now(),
        ]);
    }
}
