<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;

/**
 * ساخت متن پیام لحظه‌ای کانال و گزارش روزانه. (پورت build_message / build_daily_report)
 */
class MessageBuilder
{
    const RTL = "\u{200F}";

    /**
     * @return array{text:string, keyboard:array}
     */
    public static function buildMessage(array $d): array
    {
        $f = fn ($n) => SilverService::formatPrice($n);
        $RTL = self::RTL;

        $bubbleMithqal = $d['bubble_mithqal'];
        $bubbleGram = $d['bubble_gram'];
        $bubbleMithqalSign = $bubbleMithqal <= 0 ? '➖' : '➕';
        $bubbleGramSign = $bubbleGram <= 0 ? '➖' : '➕';

        $bar999 = $d['bar_999_price'] ?? null;
        $barNadir = $d['bar_nadir_price'] ?? null;
        $bar999Str = $bar999 !== null ? $f($bar999) : 'عدم موجودی';
        $barNadirStr = $barNadir !== null ? $f($barNadir) : 'عدم موجودی';

        $silver995 = '';
        if (! empty($d['gram_995'])) {
            $silver995 = <<<TXT

{$RTL}⚖️ <b>گرم نقره 995</b>
{$RTL}🔴 فروش: <b>{$f($d['gram_995'])}</b> تومان
{$RTL}🟢 خرید: <b>{$f($d['gram_995_buy'])}</b> تومان

{$RTL}🥈 <b>مثقال نقره 995</b>
{$RTL}🔴 فروش: <b>{$f($d['mithqal_995_price'])}</b> تومان
{$RTL}🟢 خرید: <b>{$f($d['mithqal_995_price_buy'])}</b> تومان
TXT;
        }

        $silverOunce = number_format((float) $d['silver_price'], 2, '.', '');
        $date = Jalalian::now()->format('Y/m/d');

        $text = <<<TXT

{$RTL}⚖️ <b>گرم نقره (عیار 999/9)</b>
{$RTL}🔴 فروش: <b>{$f($d['gram_price'])}</b> تومان
{$RTL}🟢 خرید: <b>{$f($d['gram_price_buy'])}</b> تومان

{$RTL}🥈 <b>مثقال نقره</b>
{$RTL}🔴 فروش: <b>{$f($d['mithqal_price'])}</b> تومان
{$RTL}🟢 خرید: <b>{$f($d['mithqal_price_buy'])}</b> تومان
{$silver995}
{$RTL}🥇 <b>شمش نقره 999/9</b> : <b>{$bar999Str}</b> تومان
{$RTL}🥈 <b>شمش نقره نادیر</b> : <b>{$barNadirStr}</b> تومان

{$RTL}⚜️ <b>انس نقره</b> : <b>{$silverOunce}</b> دلار

{$RTL}🇺🇸 <b>دلار</b> : <b>{$f($d['dollar_price'])}</b> تومان
{$RTL}💵 <b>تتر (USDT)</b> : <b>{$f($d['tether_price'])}</b> تومان
{$RTL}🇦🇪 <b>درهم</b> : <b>{$f($d['dirham_price'])}</b> تومان
{$RTL}🇪🇺 <b>یورو</b> : <b>{$f($d['euro_price'])}</b> تومان

{$RTL}{$bubbleMithqalSign} <b>حباب در هر مثقال</b> : <b>{$f($bubbleMithqal)}</b> تومان
{$RTL}{$bubbleGramSign} <b>حباب در هر گرم</b> : <b>{$f($bubbleGram)}</b> تومان

📆 {$date}
🆔 @sachme_kaf
TXT;

        $keyboard = [
            'inline_keyboard' => [[
                ['text' => '📢 عضویت در کانال', 'url' => 'https://t.me/sachme_kaf'],
                ['text' => '💰 خرید و فروش نقره', 'url' => 'https://t.me/Reza_safarpour'],
            ]],
        ];

        return ['text' => $text, 'keyboard' => $keyboard];
    }

    /** گزارش پایان روز معاملاتی. null اگر رکوردی برای امروز نباشد. */
    public static function buildDailyReport(): ?string
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT
              (SELECT MIN(mithqal_price) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime')) AS min_mithqal,
              (SELECT MAX(mithqal_price) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime')) AS max_mithqal,
              (SELECT mithqal_price FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime') ORDER BY id ASC LIMIT 1) AS first_mithqal,
              (SELECT mithqal_price FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime') ORDER BY id DESC LIMIT 1) AS last_mithqal,

              (SELECT MIN(gram_price) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime')) AS min_gram,
              (SELECT MAX(gram_price) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime')) AS max_gram,
              (SELECT gram_price FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime') ORDER BY id ASC LIMIT 1) AS first_gram,
              (SELECT gram_price FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime') ORDER BY id DESC LIMIT 1) AS last_gram,

              (SELECT MIN(silver_ounce) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime')) AS min_ounce,
              (SELECT MAX(silver_ounce) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime')) AS max_ounce,
              (SELECT silver_ounce FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime') ORDER BY id ASC LIMIT 1) AS first_ounce,
              (SELECT silver_ounce FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime') ORDER BY id DESC LIMIT 1) AS last_ounce,

              (SELECT MIN(bubble_mithqal) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime')) AS min_bubble_mithqal,
              (SELECT MAX(bubble_mithqal) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime')) AS max_bubble_mithqal,
              (SELECT MIN(bubble_gram) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime')) AS min_bubble_gram,
              (SELECT MAX(bubble_gram) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime')) AS max_bubble_gram,

              (SELECT MIN(tether_price) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime')) AS min_tether,
              (SELECT MAX(tether_price) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime')) AS max_tether,

              (SELECT MIN(dollar_price) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime')) AS min_dollar,
              (SELECT MAX(dollar_price) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime')) AS max_dollar,

              (SELECT MIN(bar_999_price) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime') AND bar_999_price IS NOT NULL) AS min_bar_999,
              (SELECT MAX(bar_999_price) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime') AND bar_999_price IS NOT NULL) AS max_bar_999,

              (SELECT MIN(bar_nadir_price) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime') AND bar_nadir_price IS NOT NULL) AS min_bar_nadir,
              (SELECT MAX(bar_nadir_price) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime') AND bar_nadir_price IS NOT NULL) AS max_bar_nadir,

              (SELECT MIN(gram_995) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime') AND gram_995 IS NOT NULL) AS min_gram_995,
              (SELECT MAX(gram_995) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime') AND gram_995 IS NOT NULL) AS max_gram_995,
              (SELECT MIN(mithqal_995_price) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime') AND mithqal_995_price IS NOT NULL) AS min_mithqal_995,
              (SELECT MAX(mithqal_995_price) FROM silver_prices WHERE DATE(timestamp)=DATE('now','localtime') AND mithqal_995_price IS NOT NULL) AS max_mithqal_995
        SQL);

        if (! $row || $row->min_bubble_mithqal === null || $row->max_bubble_mithqal === null) {
            return null;
        }

        $f = fn ($n) => SilverService::formatPrice($n);
        $RTL = self::RTL;

        $minTether = $row->min_tether ?? 0;
        $maxTether = $row->max_tether ?? 0;
        $minDollar = $row->min_dollar ?? 0;
        $maxDollar = $row->max_dollar ?? 0;

        $minBubbleMithqal = min($row->min_bubble_mithqal, $row->max_bubble_mithqal);
        $maxBubbleMithqal = max($row->min_bubble_mithqal, $row->max_bubble_mithqal);
        $minBubbleGram = min($row->min_bubble_gram, $row->max_bubble_gram);
        $maxBubbleGram = max($row->min_bubble_gram, $row->max_bubble_gram);

        $minBubbleMithqalSign = $minBubbleMithqal > 0 ? '➕' : '';
        $maxBubbleMithqalSign = $maxBubbleMithqal > 0 ? '➕' : '';
        $minBubbleGramSign = $minBubbleGram > 0 ? '➕' : '';
        $maxBubbleGramSign = $maxBubbleGram > 0 ? '➕' : '';

        $now = Jalalian::now();
        $weekdayFa = $now->format('l');
        $dateStr = $now->format('Y-m-d');

        // بخش‌های اختیاری
        $bar999Section = '';
        if ($row->min_bar_999 !== null && $row->max_bar_999 !== null) {
            $bar999Section = "\n{$RTL}شمش 999/9:\n{$RTL}🔺بیشترین : {$f($row->max_bar_999)}\n{$RTL}🔻کمترین   : {$f($row->min_bar_999)}\n";
        }

        $barNadirSection = '';
        if ($row->min_bar_nadir !== null && $row->max_bar_nadir !== null) {
            $barNadirSection = "\n{$RTL}شمش نادیر:\n{$RTL}🔺بیشترین : {$f($row->max_bar_nadir)}\n{$RTL}🔻کمترین   : {$f($row->min_bar_nadir)}\n";
        }

        $silver995Section = '';
        if ($row->min_gram_995 !== null && $row->max_gram_995 !== null) {
            $silver995Section = "\n{$RTL}گرم نقره 995:\n{$RTL}🔺بیشترین : {$f($row->max_gram_995)}\n{$RTL}🔻کمترین   : {$f($row->min_gram_995)}\n\n{$RTL} مثقال نقره 995:\n{$RTL}🔺بیشترین : {$f($row->max_mithqal_995)}\n{$RTL}🔻کمترین   : {$f($row->min_mithqal_995)}\n";
        }

        $minOunce = number_format((float) $row->min_ounce, 2, '.', '');
        $maxOunce = number_format((float) $row->max_ounce, 2, '.', '');
        $firstOunce = number_format((float) $row->first_ounce, 2, '.', '');
        $lastOunce = number_format((float) $row->last_ounce, 2, '.', '');

        return <<<TXT

{$RTL}#مثقال_نقره_ساچمه

{$RTL}گزارش روزانه
{$RTL}🗓 {$dateStr} {$weekdayFa}
{$RTL}🔖 پایان روز معاملاتی

{$RTL}مثقال نقره:
{$RTL}🔺بیشترین           : {$f($row->max_mithqal)}
{$RTL}🔻کمترین             : {$f($row->min_mithqal)}
{$RTL}🟢 اولین معامله   : {$f($row->first_mithqal)}
{$RTL}🔴 آخرین معامله : {$f($row->last_mithqal)}

{$RTL}گرم نقره (عیار 999/9):
{$RTL}🔺بیشترین            : {$f($row->max_gram)}
{$RTL}🔻کمترین             : {$f($row->min_gram)}
{$RTL}🟢 اولین معامله   : {$f($row->first_gram)}
{$RTL}🔴 آخرین معامله : {$f($row->last_gram)}
{$silver995Section}
{$RTL}انس نقره:
{$RTL}🔺بیشترین           : {$maxOunce}
{$RTL}🔻کمترین            : {$minOunce}
{$RTL}🟢 اولین معامله   : {$firstOunce}
{$RTL}🔴 آخرین معامله : {$lastOunce}

{$RTL}تتر (USDT):
{$RTL}🔺بیشترین            : {$f($maxTether)}
{$RTL}🔻کمترین             : {$f($minTether)}

{$RTL}دلار آمریکا:
{$RTL}🔺بیشترین            : {$f($maxDollar)}
{$RTL}🔻کمترین             : {$f($minDollar)}
{$bar999Section}{$barNadirSection}


{$RTL}تلورانس حباب مثقال:
{$RTL}🔺بیشترین : {$maxBubbleMithqalSign} {$f($maxBubbleMithqal)}
{$RTL}🔻کمترین   : {$minBubbleMithqalSign} {$f($minBubbleMithqal)}

{$RTL}تلورانس حباب گرم:
{$RTL}🔺بیشترین : {$maxBubbleGramSign} {$f($maxBubbleGram)}
{$RTL}🔻کمترین   : {$minBubbleGramSign} {$f($minBubbleGram)}

@sachme_kaf
TXT;
    }
}
