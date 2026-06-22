<?php

namespace App\Services;

use App\Models\BarStatus;
use App\Models\BotSetting;
use App\Models\SilverPrice;
use App\Support\BotLog;

/**
 * منطق دامنه: محاسبات قیمت، خواندن/نوشتن تنظیمات و وضعیت شمش.
 * (پورت services.py + توابع کمکی main.py)
 */
class SilverService
{
    public static function isBotActive(): bool
    {
        $row = BotSetting::find('is_active');

        return $row && (int) $row->value === 1;
    }

    public static function setBotActive(bool $on): void
    {
        BotSetting::updateOrCreate(['key' => 'is_active'], ['value' => $on ? '1' : '0']);
        BotLog::info($on ? '🟢 ربات روشن شد' : '🔴 ربات خاموش شد');
    }

    public static function getBuyPercent(): float
    {
        $row = BotSetting::find('buy_percent');

        return $row ? (float) $row->value : 0.0;
    }

    public static function setBuyPercent(float $p): void
    {
        BotSetting::updateOrCreate(['key' => 'buy_percent'], ['value' => (string) $p]);
        BotLog::info('📉 درصد خرید تغییر کرد', ['percent' => $p]);
    }

    /**
     * قیمت شمش/نقره995 از bar_status.
     * null یعنی هنوز ثبت نشده یا "unavailable" (عدم موجودی).
     */
    public static function getBarPrice(string $key): ?int
    {
        $row = BarStatus::find($key);
        if (! $row) {
            return null;
        }
        if ($row->value === 'unavailable') {
            return null;
        }

        return is_numeric($row->value) ? (int) $row->value : null;
    }

    public static function setBarStatus(string $key, $value): void
    {
        BarStatus::updateOrCreate(['key' => $key], ['value' => (string) $value]);
        BotLog::info('🥇 وضعیت شمش/نقره تغییر کرد', ['key' => $key, 'value' => $value]);
    }

    public static function resetBarStatus(): void
    {
        BarStatus::query()->delete();
    }

    public static function getLastRecordFull(): ?SilverPrice
    {
        return SilverPrice::orderByDesc('id')->first();
    }

    /** فرمت عدد با جداکننده‌ی هزارگان به‌صورت "/" (مثل پایتون) */
    public static function formatPrice($num): string
    {
        if ($num === null) {
            return '۰';
        }

        return number_format(round($num), 0, '.', '/');
    }

    /**
     * محاسبه و درج یک رکورد. خروجی: آرایه‌ی مقادیر محاسبه‌شده.
     * (پورت insert_record)
     */
    public static function insertRecord(
        $gramPrice,
        $dollarPrice,
        $tetherPrice,
        $silverPrice,
        $dirhamPrice,
        $euroPrice,
        $gram995,
        $bar999Price = null,
        $barNadirPrice = null
    ): array {
        $mithqalPrice = round($gramPrice / 0.217, 2);
        $percent = self::getBuyPercent();
        $factor = 1 - $percent / 100;
        $mithqalPriceBuy = (int) ($mithqalPrice * $factor);
        $gramPriceBuy = round($gramPrice * $factor, 2);

        if ($gram995) {
            $mithqal995Price = round($gram995 / 0.217, 2);
            $mithqal995PriceBuy = (int) ($mithqal995Price * $factor);
            $gram995Buy = round($gram995 * $factor, 2);
        } else {
            $mithqal995Price = null;
            $mithqal995PriceBuy = null;
            $gram995Buy = null;
        }

        $bubbleMithqal = $mithqalPrice - ($dollarPrice * $silverPrice / 6.75);
        $bubbleGram = round($bubbleMithqal * 0.217, 2);

        SilverPrice::create([
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'mithqal_price' => $mithqalPrice,
            'gram_price' => $gramPrice,
            'mithqal_price_buy' => $mithqalPriceBuy,
            'gram_price_buy' => $gramPriceBuy,
            'silver_ounce' => $silverPrice,
            'dollar_price' => $dollarPrice,
            'tether_price' => $tetherPrice,
            'bubble_mithqal' => $bubbleMithqal,
            'bubble_gram' => $bubbleGram,
            'dirham_price' => $dirhamPrice,
            'euro_price' => $euroPrice,
            'bar_999_price' => $bar999Price,
            'bar_nadir_price' => $barNadirPrice,
            'gram_995' => $gram995,
            'gram_995_buy' => $gram995Buy,
            'mithqal_995_price' => $mithqal995Price,
            'mithqal_995_price_buy' => $mithqal995PriceBuy,
        ]);

        BotLog::info('💰 قیمت گرفته و ذخیره شد', [
            'gram_price' => $gramPrice,
            'mithqal_price' => $mithqalPrice,
            'dollar_price' => $dollarPrice,
            'tether_price' => $tetherPrice,
            'silver_ounce' => $silverPrice,
            'gram_995' => $gram995,
            'bar_999_price' => $bar999Price,
            'bar_nadir_price' => $barNadirPrice,
        ]);

        return [
            'mithqal_price' => $mithqalPrice,
            'gram_price_buy' => $gramPriceBuy,
            'mithqal_price_buy' => $mithqalPriceBuy,
            'bubble_mithqal' => $bubbleMithqal,
            'bubble_gram' => $bubbleGram,
            'mithqal_995_price' => $mithqal995Price,
            'mithqal_995_price_buy' => $mithqal995PriceBuy,
            'gram_995_buy' => $gram995Buy,
        ];
    }
}
