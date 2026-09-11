<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();

            // Идентификатор карточки в Яндексе — по нему организация и опознаётся при повторном
            // добавлении, ссылку пользователь может вставить в другом виде (с фильтрами, из шаринга).
            $table->string('yandex_id')->unique();
            $table->string('yandex_url');
            $table->string('name')->nullable();

            $table->decimal('rating', 2, 1)->nullable();
            $table->unsignedInteger('ratings_count')->nullable();
            $table->unsignedInteger('reviews_count')->nullable();

            $table->string('status', 20)->index();
            $table->timestamp('parsed_at')->nullable();

            // Сколько отзывов уже выгружено в текущем проходе — из этого фронт рисует прогресс.
            $table->unsignedInteger('parsed_reviews')->default(0);
            $table->text('error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
