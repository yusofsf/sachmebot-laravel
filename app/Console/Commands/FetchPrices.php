<?php

namespace App\Console\Commands;

use App\Services\MessageBuilder;
use App\Services\PriceFetcher;
use App\Services\SilverService;
use App\Services\TelegramClient;
use App\Support\BotLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * کرون: در بازه‌ی کاری قیمت‌ها را به‌روز و به کانال ارسال می‌کند.
 * (پورت fetch-prices.py)
 */
class FetchPrices extends Command
{
    protected $signature = 'bot:fetch-prices';

    protected $description = 'Fetch live prices and push an update to the channel';

    public function handle(): int
    {
        $now = Carbon::now(config('app.timezone'));
        $start = (int) config('telegram.work_start_hour', 10);
        $end = (int) config('telegram.work_end_hour', 20);

        if ($now->hour < $start || $now->hour >= $end) {
            $this->info("{$now} | خارج از بازه‌ی کاری");

            return self::SUCCESS;
        }

        $fetcher = new PriceFetcher();
        $tether = $fetcher->tether();
        $dollar = $fetcher->dollar();
        $silver = $fetcher->silverOunce();
        $dirham = $fetcher->dirham();
        $euro = $fetcher->euro();

        if ($dollar === null || $silver === null) {
            $this->warn('❌ یکی از قیمت‌ها (دلار یا نقره) در دسترس نیست.');
            BotLog::warning('⏭️ fetch-prices رد شد: دلار یا انس نقره در دسترس نیست', [
                'dollar' => $dollar, 'silver' => $silver,
            ]);

            return self::SUCCESS;
        }

        $last = SilverService::getLastRecordFull();
        if (! $last || ! $last->gram_price || ! $last->gram_995) {
            $this->warn('❌ هیچ قیمت گرمی (یا 995) قبلاً ثبت نشده.');
            BotLog::warning('⏭️ fetch-prices رد شد: هنوز قیمت پایه ثبت نشده');

            return self::SUCCESS;
        }

        $bar999 = SilverService::getBarPrice('bar_999');
        $barNadir = SilverService::getBarPrice('bar_nadir');

        $r = SilverService::insertRecord(
            $last->gram_price, $dollar, $tether, $silver, $dirham, $euro,
            $last->gram_995, $bar999, $barNadir
        );

        if (! SilverService::isBotActive()) {
            $this->info('ربات خاموش است؛ پیام به کانال ارسال نشد');
            BotLog::info('⏭️ ربات خاموش است؛ fetch-prices به کانال نفرستاد');

            return self::SUCCESS;
        }

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
            'gram_995' => $last->gram_995,
            'gram_995_buy' => $r['gram_995_buy'],
            'mithqal_995_price' => $r['mithqal_995_price'],
            'mithqal_995_price_buy' => $r['mithqal_995_price_buy'],
            'bar_999_price' => $bar999,
            'bar_nadir_price' => $barNadir,
        ];

        $built = MessageBuilder::buildMessage($data);

        (new TelegramClient())->sendMessage(
            config('telegram.channel'), $built['text'], $built['keyboard']
        );

        $this->info('✅ قیمت جدید به کانال ارسال شد');
        BotLog::info('📤 قیمت به کانال ارسال شد', $data + [
            'channel' => config('telegram.channel'),
            'message_text' => $built['text'],
        ]);

        return self::SUCCESS;
    }
}
