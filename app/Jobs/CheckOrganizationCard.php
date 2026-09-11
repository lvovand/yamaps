<?php

namespace App\Jobs;

use App\Enums\FetchMode;
use App\Enums\ParseStatus;
use App\Models\Organization;
use App\Services\YandexMaps\Exceptions\ParsingFailed;
use App\Services\YandexMaps\OrganizationLink;
use App\Services\YandexMaps\ReviewsPage;
use App\Services\YandexMaps\Transport;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Первый, лёгкий шаг после добавления ссылки: одна загрузка карточки, чтобы узнать название,
 * рейтинг и — главное — сколько всего отзывов.
 *
 * От этого числа зависит, нужен ли вообще разговор с пользователем. Источник отдаёт не больше
 * 600 отзывов на одну выдачу, поэтому карточку поменьше можно выгрузить целиком и молча.
 * А если отзывов больше, выбор неизбежен: взять свежие быстро или собирать долго по нескольким
 * срезам. Решать за пользователя тут неправильно — цена вопроса минуты работы и десятки запросов.
 */
class CheckOrganizationCard implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public Organization $organization) {}

    public function uniqueId(): string
    {
        return (string) $this->organization->id;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $link = OrganizationLink::parse($this->organization->yandex_url);

        if ($link === null) {
            $this->markFailed('Ссылка на организацию больше не разбирается.');

            return;
        }

        $this->organization->update(['status' => ParseStatus::Checking, 'error' => null]);

        $url = $link->canonicalUrl();
        $page = ReviewsPage::parse(Transport::make()->get($url)->body(), $url);
        $data = $page->organization;

        $this->organization->update([
            'name' => $data->name,
            'rating' => $data->rating,
            'ratings_count' => $data->ratingsCount,
            'reviews_count' => $data->reviewsCount,
        ]);

        // Карточку уже выгружали — значит режим выбран раньше, и спрашивать заново незачем.
        // Иначе повторное добавление той же ссылки сбрасывало бы готовую организацию в вопрос.
        $answered = $this->organization->parsed_at !== null;

        if (! $answered && ($data->reviewsCount ?? 0) > config('parsing.slice_limit')) {
            $this->organization->update(['status' => ParseStatus::AwaitingChoice]);

            return;
        }

        $this->organization->update([
            // Отзывов немного — выгрузка одним срезом заберёт их все, спрашивать не о чем.
            // У организации, которую уже выгружали, режим оставляем прежний.
            'fetch_mode' => $answered ? $this->organization->fetch_mode : FetchMode::Recent,
            'status' => ParseStatus::Pending,
        ]);

        ParseOrganizationReviews::dispatch($this->organization);
    }

    private function markFailed(string $message): void
    {
        $this->organization->update(['status' => ParseStatus::Failed, 'error' => $message]);
    }

    public function failed(?Throwable $e): void
    {
        $this->markFailed(
            $e instanceof ParsingFailed
                ? $e->userMessage()
                : 'Не удалось открыть карточку организации. Проверьте ссылку и попробуйте ещё раз.',
        );
    }
}
