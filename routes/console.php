<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('bot:warmup')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->when(fn () => (bool) config('telegram.warmup_enabled', true));

// هر دقیقه قیمت‌ها را مقایسه می‌کند؛ فقط هنگام تغییر یکی از اقلام اصلی پیام می‌فرستد.
Schedule::command('bot:fetch-prices')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->between('10:00', '20:00');

Schedule::command('bot:send-daily')
    ->dailyAt('20:05');
