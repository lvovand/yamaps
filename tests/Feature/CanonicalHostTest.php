<?php

namespace Tests\Feature;

use Tests\TestCase;

class CanonicalHostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://yamaps.prettysites.ru']);
    }

    public function test_уводит_с_www_на_основной_адрес(): void
    {
        // Иначе www и голый домен — два разных сайта с разными сессиями,
        // и вход на www не запоминается.
        $this->get('https://www.yamaps.prettysites.ru/organizations/1')
            ->assertRedirect('https://yamaps.prettysites.ru/organizations/1');
    }

    public function test_основной_адрес_не_трогает(): void
    {
        $this->get('https://yamaps.prettysites.ru/login')->assertOk();
    }

    public function test_не_вмешивается_в_другие_хосты(): void
    {
        // Временная ссылка от панели хостинга и локальная разработка должны работать как есть.
        $this->get('http://localhost/login')->assertOk();
    }
}
