<?php

namespace Tests\Unit;

use App\Services\YandexMaps\OrganizationLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrganizationLinkTest extends TestCase
{
    #[DataProvider('validLinks')]
    public function test_достаёт_идентификатор_организации(string $url, string $expectedId): void
    {
        $link = OrganizationLink::parse($url);

        $this->assertNotNull($link, "Ссылка должна разбираться: {$url}");
        $this->assertSame($expectedId, $link->id);
    }

    #[DataProvider('invalidLinks')]
    public function test_отклоняет_неподходящие_ссылки(string $url): void
    {
        $this->assertNull(OrganizationLink::parse($url));
    }

    public function test_собирает_канонический_адрес_карточки(): void
    {
        $link = OrganizationLink::parse('https://yandex.ru/maps/org/kofeinya/1124715036/?ll=37.6%2C55.7&z=17');

        $this->assertSame('https://yandex.ru/maps/org/1124715036/reviews/', $link->canonicalUrl());
    }

    public function test_опознаёт_короткую_ссылку_шаринга(): void
    {
        // Организации в ней нет, поэтому обычный разбор её не берёт — но и мусором она не является.
        $this->assertTrue(OrganizationLink::isShort('https://yandex.ru/maps/-/CThpBKJS'));
        $this->assertTrue(OrganizationLink::isShort('https://yandex.com/maps/-/CDdbYZ3M'));
        $this->assertNull(OrganizationLink::parse('https://yandex.ru/maps/-/CThpBKJS'));
    }

    public function test_не_принимает_за_короткую_ссылку_что_попало(): void
    {
        $this->assertFalse(OrganizationLink::isShort('https://yandex.ru/maps/org/termoland/3855941798/'));
        $this->assertFalse(OrganizationLink::isShort('https://example.com/maps/-/CThpBKJS'));
        $this->assertFalse(OrganizationLink::isShort('https://yandex.ru/maps/213/moscow/'));
    }

    public static function validLinks(): array
    {
        return [
            'обычная карточка' => [
                'https://yandex.ru/maps/org/kofeinya/1124715036/',
                '1124715036',
            ],
            'вкладка отзывов' => [
                'https://yandex.ru/maps/org/kofeinya/1124715036/reviews/',
                '1124715036',
            ],
            'с регионом в пути' => [
                'https://yandex.ru/maps/213/moscow/org/kofeinya/1124715036/',
                '1124715036',
            ],
            'без названия в пути' => [
                'https://yandex.ru/maps/org/1124715036/',
                '1124715036',
            ],
            'с параметрами карты' => [
                'https://yandex.ru/maps/org/kofeinya/1124715036/?ll=37.61%2C55.75&z=16',
                '1124715036',
            ],
            'домен com' => [
                'https://yandex.com/maps/org/kofeinya/1124715036/',
                '1124715036',
            ],
            'с www' => [
                'https://www.yandex.ru/maps/org/kofeinya/1124715036/',
                '1124715036',
            ],
            'раздел профиля' => [
                'https://yandex.ru/profile/1124715036',
                '1124715036',
            ],
            'точка открыта на карте' => [
                'https://yandex.com/maps/2/saint-petersburg/?ll=30.33%2C59.82&mode=poi&poi%5Bpoint%5D=30.31%2C59.82&poi%5Buri%5D=ymapsbm1%3A%2F%2Forg%3Foid%3D3855941798&tab=reviews&z=13',
                '3855941798',
            ],
        ];
    }

    public static function invalidLinks(): array
    {
        return [
            'пустая строка' => [''],
            'не ссылка' => ['просто текст'],
            'чужой домен' => ['https://2gis.ru/moscow/firm/1124715036'],
            'домен-подделка' => ['https://yandex.ru.evil.com/maps/org/kofeinya/1124715036/'],
            'карта без организации' => ['https://yandex.ru/maps/213/moscow/'],
            'поиск по картам' => ['https://yandex.ru/maps/?text=кофейня'],
        ];
    }
}
