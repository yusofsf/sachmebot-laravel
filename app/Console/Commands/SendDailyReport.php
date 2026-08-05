<?php

namespace App\Console\Commands;

use App\Services\MessageBuilder;
use App\Services\SilverService;
use App\Services\TelegramClient;
use App\Support\BotLog;
use Illuminate\Console\Command;

/**
 * کرون: گزارش پایان روز معاملاتی را به کانال می‌فرستد.
 * (پورت send-daily.py)
 */
class SendDailyReport extends Command
{
    protected $signature = 'bot:send-daily';

    protected $description = 'Send the end-of-day silver report to the channel';

    public function handle(): int
    {
        if (! SilverService::isBotActive()) {
            $this->info('ربات خاموش است؛ گزارش روزانه ارسال نشد');
            BotLog::info('⏭️ ربات خاموش است؛ گزارش روزانه ارسال نشد');

            return self::SUCCESS;
        }

        $message = MessageBuilder::buildDailyReport();

        if (! $message) {
            $this->warn('❌ هیچ رکوردی برای امروز پیدا نشد.');
            BotLog::warning('⏭️ گزارش روزانه رد شد: رکوردی برای امروز نیست');

            return self::SUCCESS;
        }

        try {
            (new TelegramClient)->sendMessage(config('telegram.channel'), $message);
        } catch (\Throwable $e) {
            $this->error('Telegram send failed: '.$e->getMessage());
            BotLog::warning('❌ ارسال گزارش روزانه به کانال ناموفق بود', [
                'channel' => config('telegram.channel'),
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return self::FAILURE;
        }
        $this->info('✅ گزارش روزانه ارسال شد');
        BotLog::info('📤 گزارش روزانه به کانال ارسال شد', [
            'channel' => config('telegram.channel'),
            'message_text' => $message,
        ]);

        return self::SUCCESS;
    }
}
