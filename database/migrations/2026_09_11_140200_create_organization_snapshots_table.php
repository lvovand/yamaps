<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Снимок показателей организации на момент удачного парсинга.
     * Сама таблица organizations хранит только текущее состояние, а здесь видно,
     * как рейтинг и счётчики менялись от прохода к проходу.
     */
    public function up(): void
    {
        Schema::create('organization_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->decimal('rating', 2, 1)->nullable();
            $table->unsignedInteger('ratings_count')->nullable();
            $table->unsignedInteger('reviews_count')->nullable();

            $table->timestamp('created_at');

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_snapshots');
    }
};
