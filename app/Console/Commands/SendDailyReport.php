<?php

namespace App\Console\Commands;

use App\Services\MessageBuilder;
use App\Services\SilverService;
use App\Services\TelegramClient;
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

            return self::SUCCESS;
        }

        $message = MessageBuilder::buildDailyReport();

        if (! $message) {
            $this->warn('❌ هیچ رکوردی برای امروز پیدا نشد.');

            return self::SUCCESS;
        }

        (new TelegramClient())->sendMessage(config('telegram.channel'), $message);
        $this->info('✅ گزارش روزانه ارسال شد');

        return self::SUCCESS;
    }
}
