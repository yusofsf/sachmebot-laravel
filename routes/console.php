<?php

use Illuminate\Support\Facades\Schedule;

// معادل کرون‌های پایتون. زمان‌بندی بر اساس APP_TIMEZONE (Asia/Tehran).
Schedule::command('bot:fetch-prices')
    ->everyFiveMinutes()
    ->between('10:00', '20:00');

Schedule::command('bot:send-daily')
    ->dailyAt('20:05');
