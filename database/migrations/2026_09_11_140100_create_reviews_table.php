<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Идентификатор отзыва у Яндекса. Пара с организацией уникальна — на неё опирается
            // upsert, чтобы повторный парсинг обновлял отзывы, а не плодил копии.
            $table->string('yandex_id');

            $table->string('author')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('text')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'yandex_id']);
            // Отзывы всегда отдаём страницами от свежих к старым — индекс под этот порядок.
            $table->index(['organization_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
