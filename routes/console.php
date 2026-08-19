<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Запуск ежеминутной проверки напоминаний в планировщике Laravel Scheduler.
// withoutOverlapping() — дополнительная защита на случай, если один запуск
// выполняется дольше минуты; основная защита от дублей — атомарное
// резервирование статуса pending -> dispatching внутри самой команды.
Schedule::command('reminders:dispatch')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('reminders:prune-history')
    ->dailyAt('03:30')
    ->withoutOverlapping();
