<?php

namespace App\Services\YandexMaps;

/**
 * Ссылка на карточку организации, разобранная до идентификатора.
 *
 * Пользователь может принести ссылку в заметно разном виде: скопированную из адресной строки
 * с хвостом фильтров, со вкладки отзывов, с региональным префиксом или вовсе из раздела
 * «Профиль». Для парсера важен только числовой идентификатор карточки.
 */
final readonly class OrganizationLink
{
    private function __construct(
        public string $id,
        public string $url,
    ) {}

    /**
     * Ссылка-шаринг вида yandex.ru/maps/-/CThpBKJS. Идентификатора в ней нет — он появится
     * только после того, как Яндекс скажет, куда она ведёт.
     */
    public static function isShort(string $url): bool
    {
        $url = trim($url);
        $parts = parse_url($url);

        return $parts !== false
            && self::isYandexHost($parts['host'] ?? '')
            && preg_match('~^/maps/-/[A-Za-z0-9_-]+/?$~', $parts['path'] ?? '') === 1;
    }

    public static function parse(string $url): ?self
    {
        $url = trim($url);

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);

        if (! self::isYandexHost($parts['host'] ?? '')) {
            return null;
        }

        $id = self::extractId($parts['path'] ?? '')
            ?? self::extractIdFromPoi($parts['query'] ?? '');

        return $id === null ? null : new self($id, $url);
    }

    /**
     * Адрес карточки в каноническом виде — от него отталкивается парсер,
     * чтобы не тащить за собой пользовательские параметры вроде выбранного фильтра отзывов.
     */
    public function canonicalUrl(): string
    {
        return "https://yandex.ru/maps/org/{$this->id}/reviews/";
    }

    private static function isYandexHost(string $host): bool
    {
        $host = preg_replace('~^www\.~', '', strtolower($host));

        return in_array($host, ['yandex.ru', 'yandex.com', 'yandex.by', 'yandex.kz', 'maps.yandex.ru'], true);
    }

    private static function extractId(string $path): ?string
    {
        // Идентификатор идёт последним числом после /org/ — слог с названием может отсутствовать,
        // а перед ним может стоять регион (/maps/213/moscow/org/...).
        if (preg_match('~/org/(?:[^/]+/)?(\d+)~', $path, $m) === 1) {
            return $m[1];
        }

        // Карточка того же заведения в «Профиле» — идентификатор тот же самый.
        if (preg_match('~/profile/(?:[^/]+/)?(\d+)~', $path, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Если точку открыли прямо на карте, Яндекс не меняет путь, а кладёт организацию
     * в параметр poi[uri] вида ymapsbm1://org?oid=3855941798. Ссылку в таком виде
     * копируют из адресной строки чаще всего.
     */
    private static function extractIdFromPoi(string $query): ?string
    {
        parse_str($query, $params);

        $uri = $params['poi']['uri'] ?? null;

        if (! is_string($uri)) {
            return null;
        }

        return preg_match('~\boid=(\d+)~', $uri, $m) === 1 ? $m[1] : null;
    }
}
