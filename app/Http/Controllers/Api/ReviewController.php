<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Organization;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReviewController extends Controller
{
    /**
     * Отзывы отдаём из своей базы, а не ходим за ними в Яндекс на каждое перелистывание:
     * страница открывается мгновенно и не тратит лимит обращений к источнику.
     */
    public function index(Organization $organization): AnonymousResourceCollection
    {
        $reviews = $organization->reviews()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(50);

        return ReviewResource::collection($reviews);
    }
}
