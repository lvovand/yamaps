<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationSnapshot extends Model
{
    // Снимок пишется один раз и больше не меняется, поэтому updated_at здесь лишний.
    public const UPDATED_AT = null;

    protected $fillable = [
        'rating',
        'ratings_count',
        'reviews_count',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
