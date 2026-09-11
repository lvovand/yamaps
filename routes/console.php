<?php

use Illuminate\Support\Facades\Schedule;

// На хостинге нет supervisor, поэтому воркер очереди поднимаем планировщиком раз в минуту.
// Он разбирает накопившиеся задачи и завершается, не дожив до следующего запуска.
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();
