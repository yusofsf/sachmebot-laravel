<?php

namespace App\Console\Commands;

use App\Services\GoldPriceService;
use App\Services\MessageBuilder;
use App\Services\PriceFetcher;
use App\Services\SilverService;
use App\Services\TelegramClient;
use App\Support\BotLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * کرون: هر دقیقه قیمت‌های اصلی را بررسی و فقط در صورت تغییر به کانال ارسال می‌کند.
 * (پورت fetch-prices.py)
 */
class FetchPrices extends Command
{
    protected $signature = 'bot:fetch-prices';

    protected $description = 'Send an update when a tracked silver or gold price changes';

    public function handle(): int
    {
        $now = Carbon::now(config('app.timezone'));
        $start = (int) config('telegram.work_start_hour', 10);
        $end = (int) config('telegram.work_end_hour', 20);

        if ($now->hour < $start || $now->hour >= $end) {
            $this->info("{$now} | خارج از بازه‌ی کاری");

            return self::SUCCESS;
        }

        $last = SilverService::getLastRecordFull();
        $gram995 = SilverService::getBarPrice('silver_995') ?? $last?->gram_995;
        if (! $last || ! $last->gram_price || ! $gram995) {
            $this->warn('❌ هیچ قیمت گرمی (یا 995) قبلاً ثبت نشده.');
            BotLog::warning('⏭️ fetch-prices رد شد: هنوز قیمت پایه ثبت نشده');

            return self::SUCCESS;
        }

        $gold = (new GoldPriceService)->latest();
        if ($gold === null) {
            $this->warn('❌ قیمت طلا از دیتابیس سایت خوانده نشد.');

            return self::SUCCESS;
        }

        $tracked = [
            'silver_mithqal_995' => round($gram995 / 0.217, 2),
            'silver_mithqal_999' => round($last->gram_price / 0.217, 2),
            'silver_gram_995' => (float) $gram995,
            'silver_gram_999' => (float) $last->gram_price,
            'gold_bahar' => $gold['bahar_sell'],
            'gold_bahar_buy' => $gold['bahar_buy'],
            'gold_nim' => $gold['nim_sell'],
            'gold_nim_buy' => $gold['nim_buy'],
            'gold_rob' => $gold['rob_sell'],
            'gold_rob_buy' => $gold['rob_buy'],
            'gold_mithqal' => $gold['mithqal_sell'],
            'gold_mithqal_buy' => $gold['mithqal_buy'],
            'gold_gram' => $gold['geram_sell'],
            'gold_gram_buy' => $gold['geram_buy'],
        ];
        $previous = SilverService::getLastSentTrackedPrices();

        if (! SilverService::trackedPricesChanged($tracked, $previous)) {
            $this->info('قیمت‌های اصلی تغییری نکرده‌اند؛ پیام ارسال نشد');

            return self::SUCCESS;
        }

        if (! SilverService::isBotActive()) {
            $this->info('ربات خاموش است؛ پیام به کانال ارسال نشد');
            BotLog::info('⏭️ ربات خاموش است؛ fetch-prices به کانال نفرستاد');

            return self::SUCCESS;
        }

        // قیمت‌های ارز و انس فقط برای تکمیل متن و محاسبه‌ی حباب هستند و به‌تنهایی
        // باعث ارسال نمی‌شوند. در خطای شبکه از آخرین مقدار ثبت‌شده استفاده می‌کنیم.
        $prices = (new PriceFetcher)->fetchAll();
        $tether = $prices['tether'] ?? $last->tether_price;
        $dollar = $prices['dollar'] ?? $last->dollar_price;
        $silver = $prices['silver'] ?? $last->silver_ounce;
        $dirham = $prices['dirham'] ?? $last->dirham_price;
        $euro = $prices['euro'] ?? $last->euro_price;

        if ($dollar === null || $silver === null) {
            $this->warn('❌ قیمت دلار یا انس نقره برای ساخت پیام در دسترس نیست.');

            return self::SUCCESS;
        }

        (new GoldPriceService)->updateLatestSilverOunce((float) $silver);

        $bar999 = SilverService::getBarPrice('bar_999');
        $barNadir = SilverService::getBarPrice('bar_nadir');
        $r = SilverService::insertRecord(
            $last->gram_price, $dollar, $tether, $silver, $dirham, $euro,
            $gram995, $bar999, $barNadir
        );

        $data = [
            'mithqal_price' => $r['mithqal_price'],
            'gram_price' => $last->gram_price,
            'mithqal_price_buy' => $r['mithqal_price_buy'],
            'gram_price_buy' => $r['gram_price_buy'],
            'silver_price' => $silver,
            'dollar_price' => $dollar,
            'tether_price' => $tether,
            'bubble_mithqal' => $r['bubble_mithqal'],
            'bubble_gram' => $r['bubble_gram'],
            'dirham_price' => $dirham,
            'euro_price' => $euro,
            'gram_995' => $gram995,
            'gram_995_buy' => $r['gram_995_buy'],
            'mithqal_995_price' => $r['mithqal_995_price'],
            'mithqal_995_price_buy' => $r['mithqal_995_price_buy'],
            'bar_999_price' => $bar999,
            'bar_nadir_price' => $barNadir,
            'gold' => $gold,
        ];

        try {
            $built = MessageBuilder::buildMessage($data);

            (new TelegramClient)->sendMessage(
                config('telegram.channel'), $built['text'], $built['keyboard']
            );
            SilverService::saveLastSentTrackedPrices($tracked);
        } catch (\Throwable $e) {
            $this->error('Telegram send failed: '.$e->getMessage());
            BotLog::warning('❌ ارسال قیمت به کانال ناموفق بود', [
                'channel' => config('telegram.channel'),
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return self::FAILURE;
        }

        $this->info('✅ قیمت جدید به کانال ارسال شد');
        BotLog::info('📤 قیمت به کانال ارسال شد', $data + [
            'channel' => config('telegram.channel'),
            'message_text' => $built['text'],
        ]);

        return self::SUCCESS;
    }
}
