<?php

namespace App\Http\Controllers\Api;

use App\Enums\ParseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartParsingRequest;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Jobs\CheckOrganizationCard;
use App\Jobs\ParseOrganizationReviews;
use App\Models\Organization;
use App\Services\YandexMaps\OrganizationLink;
use App\Services\YandexMaps\ShortLinkResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    public function __construct(private readonly ShortLinkResolver $resolver) {}

    public function index(): AnonymousResourceCollection
    {
        return OrganizationResource::collection(
            Organization::withCount(['reviews as collected_reviews'])->latest()->get(),
        );
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $url = $request->string('url')->toString();

        // Короткой ссылкой делятся из приложения Карт, и организация в ней не указана —
        // приходится один раз сходить за редиректом, чтобы узнать, о ком речь.
        $link = OrganizationLink::isShort($url)
            ? $this->resolver->resolve($url)
            : OrganizationLink::parse($url);

        if ($link === null) {
            throw ValidationException::withMessages([
                'url' => 'Не удалось определить организацию по этой ссылке. Откройте её в браузере и скопируйте адрес карточки.',
            ]);
        }

        // Одну и ту же карточку можно принести ссылкой в разном виде, поэтому опознаём
        // организацию по идентификатору: повторное добавление обновляет запись, а не дублирует её.
        $organization = Organization::updateOrCreate(
            ['yandex_id' => $link->id],
            [
                'yandex_url' => $link->canonicalUrl(),
                'status' => ParseStatus::Pending,
                'parsed_reviews' => 0,
                'error' => null,
            ],
        );

        // Сначала только смотрим карточку: по числу отзывов станет понятно, можно ли
        // выгружать сразу или придётся спросить у пользователя, насколько глубоко копать.
        CheckOrganizationCard::dispatch($organization);

        return OrganizationResource::make($organization->loadCount(['reviews as collected_reviews']))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Запуск выгрузки после того, как пользователь выбрал режим.
     */
    public function start(StartParsingRequest $request, Organization $organization): OrganizationResource
    {
        $organization->update([
            'fetch_mode' => $request->fetchMode(),
            'status' => ParseStatus::Pending,
            'parsed_reviews' => 0,
            'error' => null,
        ]);

        ParseOrganizationReviews::dispatch($organization);

        return OrganizationResource::make($organization->loadCount(['reviews as collected_reviews']));
    }

    public function show(Organization $organization): OrganizationResource
    {
        return OrganizationResource::make($organization->loadCount(['reviews as collected_reviews']));
    }

    /**
     * Повторная выгрузка по кнопке: показатели обновятся, отзывы допишутся поверх старых.
     */
    public function refresh(Organization $organization): OrganizationResource
    {
        $organization->update([
            'status' => ParseStatus::Pending,
            'parsed_reviews' => 0,
            'error' => null,
        ]);

        ParseOrganizationReviews::dispatch($organization);

        return OrganizationResource::make($organization->loadCount(['reviews as collected_reviews']));
    }
}
