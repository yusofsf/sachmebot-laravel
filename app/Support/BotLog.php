<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * لاگ رویدادهای کسب‌وکار ربات (نه خطاهای فنی) در storage/logs/bot.log
 * تا جدا از laravel.log قابل دنبال‌کردن باشد: tail -f storage/logs/bot.log
 */
class BotLog
{
    public static function info(string $message, array $context = []): void
    {
        Log::channel('bot')->info($message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        Log::channel('bot')->warning($message, $context);
    }
}
