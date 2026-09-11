<?php

namespace App\Services\YandexMaps;

use RuntimeException;

/**
 * Источник перестал отдавать отзывы в текущей выдаче.
 *
 * Отдельный тип нужен, чтобы не путать это с поломкой: упереться в потолок — штатная
 * ситуация, после которой выгрузка просто переходит к следующему срезу.
 */
class ReviewsDepthLimitReached extends RuntimeException {}
