<?php

namespace App\Jobs;

use App\Enums\ParseStatus;
use App\Models\Organization;
use App\Models\Review;
use App\Services\YandexMaps\Data\OrganizationData;
use App\Services\YandexMaps\Data\ReviewData;
use App\Services\YandexMaps\Exceptions\ParsingFailed;
use App\Services\YandexMaps\Exceptions\SourceBlocked;
use App\Services\YandexMaps\Exceptions\SourceChanged;
use App\Services\YandexMaps\OrganizationLink;
use App\Services\YandexMaps\ReviewsScraper;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Выгружает отзывы организации в фоне.
 *
 * Синхронно это делать нельзя: полторы тысячи отзывов — это три десятка запросов с паузами,
 * то есть минуты. HTTP-запрос столько не живёт, да и пользователю незачем ждать у белого экрана.
 */
class ParseOrganizationReviews implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Выгрузка идёт с паузами: три десятка страниц — это несколько минут.
     * Стандартной минуты не хватает, воркер обрывал бы задачу на середине.
     */
    public int $timeout = 900;

    public function __construct(public Organization $organization) {}

    /**
     * Две задачи на одну организацию одновременно означали бы двойной поток запросов
     * к Яндексу с одного адреса — ровно то, за что банят.
     */
    public function uniqueId(): string
    {
        return (string) $this->organization->id;
    }

    /**
     * Паузы между попытками растут: если источник осадил нас один раз, лезть через минуту
     * второй раз бессмысленно.
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $link = OrganizationLink::parse($this->organization->yandex_url);

        if ($link === null) {
            $this->markFailed('Ссылка на организацию больше не разбирается.');

            return;
        }

        $this->organization->update([
            'status' => ParseStatus::Parsing,
            'parsed_reviews' => 0,
            'error' => null,
        ]);

        try {
            $data = ReviewsScraper::make()->scrape(
                $link,
                $this->organization->fetch_mode,
                $this->saveReviews(...),
            );
        } catch (SourceBlocked|SourceChanged $e) {
            // Ни бан, ни смена формата сами собой за минуту не рассосутся — повторять нечего.
            $this->markFailed($e->userMessage());
            $this->fail($e);

            return;
        } catch (ParsingFailed $e) {
            // Сетевой сбой — как раз тот случай, ради которого у задачи есть повторы.
            Log::warning('Парсинг сорвался, будет повтор', [
                'организация' => $this->organization->id,
                'ошибка' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->finish($data);
    }

    /**
     * @param  ReviewData[]  $reviews
     */
    private function saveReviews(array $reviews, int $collected, int $expected): void
    {
        // Пара «организация + идентификатор отзыва» уникальна, поэтому повторный проход
        // обновляет уже сохранённые отзывы вместо создания дублей.
        Review::upsert(
            array_map(fn ($review) => $review->toDatabaseRow($this->organization->id), $reviews),
            ['organization_id', 'yandex_id'],
            ['author', 'rating', 'text', 'published_at'],
        );

        $this->organization->update(['parsed_reviews' => $collected]);

        Log::info('Порция отзывов сохранена', [
            'организация' => $this->organization->id,
            'в порции' => count($reviews),
            'собрано' => "{$collected}/{$expected}",
        ]);
    }

    private function finish(OrganizationData $data): void
    {
        $this->organization->update([
            'name' => $data->name,
            'rating' => $data->rating,
            'ratings_count' => $data->ratingsCount,
            'reviews_count' => $data->reviewsCount,
            'status' => ParseStatus::Ready,
            'parsed_at' => now(),
            'error' => null,
        ]);

        $this->organization->recordSnapshot();
    }

    private function markFailed(string $message): void
    {
        $this->organization->update([
            'status' => ParseStatus::Failed,
            'error' => $message,
        ]);
    }

    /**
     * Сюда попадаем, когда закончились повторы. Статус уже мог быть выставлен выше —
     * но если задачу убило что-то неожиданное, пользователь всё равно должен увидеть причину.
     */
    public function failed(?Throwable $e): void
    {
        if ($this->organization->fresh()?->status !== ParseStatus::Failed) {
            $this->markFailed(
                $e instanceof ParsingFailed
                    ? $e->userMessage()
                    : 'Не удалось загрузить отзывы. Попробуйте запустить обновление ещё раз.',
            );
        }
    }
}
