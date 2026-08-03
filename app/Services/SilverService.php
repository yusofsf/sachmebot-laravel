<?php

namespace App\Services;

use App\Models\BarStatus;
use App\Models\BotSetting;
use App\Models\SilverPrice;
use Illuminate\Support\Facades\Cache;

/**
 * منطق دامنه: محاسبات قیمت، خواندن/نوشتن تنظیمات و وضعیت شمش.
 * (پورت services.py + توابع کمکی main.py)
 */
class SilverService
{
    private const BOT_ACTIVE_CACHE_KEY = 'bot:setting:is_active';

    private const BUY_PERCENT_CACHE_KEY = 'bot:setting:buy_percent';

    private const BAR_CACHE_PREFIX = 'bot:bar:';

    private const BAR_UNAVAILABLE = '__unavailable__';

    public static function isBotActive(): bool
    {
        return (bool) Cache::rememberForever(
            self::BOT_ACTIVE_CACHE_KEY,
            function (): bool {
                $row = BotSetting::find('is_active');

                return $row && (int) $row->value === 1;
            }
        );
    }

    public static function setBotActive(bool $on): void
    {
        BotSetting::updateOrCreate(['key' => 'is_active'], ['value' => $on ? '1' : '0']);
        Cache::forever(self::BOT_ACTIVE_CACHE_KEY, $on);
    }

    public static function getBuyPercent(): float
    {
        return (float) Cache::rememberForever(
            self::BUY_PERCENT_CACHE_KEY,
            function (): float {
                $row = BotSetting::find('buy_percent');

                return $row ? (float) $row->value : 0.0;
            }
        );
    }

    public static function setBuyPercent(float $p): void
    {
        BotSetting::updateOrCreate(['key' => 'buy_percent'], ['value' => (string) $p]);
        Cache::forever(self::BUY_PERCENT_CACHE_KEY, $p);
    }

    /**
     * قیمت شمش/نقره995 از bar_status.
     * null یعنی هنوز ثبت نشده یا "unavailable" (عدم موجودی).
     */
    public static function getBarPrice(string $key): ?int
    {
        $value = Cache::rememberForever(
            self::BAR_CACHE_PREFIX.$key,
            function () use ($key): int|string {
                $row = BarStatus::find($key);

                if (! $row || $row->value === 'unavailable') {
                    return self::BAR_UNAVAILABLE;
                }

                return is_numeric($row->value)
                    ? (int) $row->value
                    : self::BAR_UNAVAILABLE;
            }
        );

        return $value === self::BAR_UNAVAILABLE ? null : (int) $value;
    }

    public static function setBarStatus(string $key, $value): void
    {
        BarStatus::updateOrCreate(['key' => $key], ['value' => (string) $value]);
        Cache::forever(
            self::BAR_CACHE_PREFIX.$key,
            is_numeric($value) ? (int) $value : self::BAR_UNAVAILABLE
        );
    }

    public static function resetBarStatus(): void
    {
        BarStatus::query()->delete();
        Cache::forget(self::BAR_CACHE_PREFIX.'bar_999');
        Cache::forget(self::BAR_CACHE_PREFIX.'bar_nadir');
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
