<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ParsingSettingsController;
use App\Http\Controllers\Api\ReviewController;
use Illuminate\Support\Facades\Route;

// Подбор пароля ограничиваем на уровне маршрута: регистрации нет, пользователь один.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
    Route::post('/organizations/{organization}/start', [OrganizationController::class, 'start']);
    Route::post('/organizations/{organization}/refresh', [OrganizationController::class, 'refresh']);
    Route::get('/organizations/{organization}/reviews', [ReviewController::class, 'index']);

    Route::get('/settings/parsing', [ParsingSettingsController::class, 'show']);
    Route::put('/settings/parsing', [ParsingSettingsController::class, 'update']);
    Route::get('/settings/parsing/proxies/{index}/password', [ParsingSettingsController::class, 'revealPassword']);
    Route::post('/settings/parsing/check-proxy', [ParsingSettingsController::class, 'checkProxy']);
});
