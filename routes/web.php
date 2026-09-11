<?php

use Illuminate\Support\Facades\Route;

// Весь фронтенд — одно SPA на Vue Router, поэтому любой путь отдаёт одну и ту же страницу.
// Исключаем api, sanctum и служебный /up, чтобы они обрабатывались своими маршрутами.
Route::view('/{any?}', 'app')
    ->where('any', '^(?!api|sanctum|up|build|storage).*$');
