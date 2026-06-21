<?php

namespace App\Http\Controllers;

use App\Services\MessageBuilder;
use App\Services\PriceFetcher;
use App\Services\SilverService;
use App\Services\TelegramClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Morilog\Jalali\Jalalian;

class TelegramWebhookController extends Controller
{
    // ---------- متن دکمه‌های منوی اصلی (Reply Keyboard) ----------
    const BTN_PRICE = '💰 تعیین قیمت نقره';

    const BTN_PRICE_995 = '💰 تعیین قیمت نقره 995';

    const BTN_PERCENT = '📉 درصد قیمت خرید';

    const BTN_BAR_999 = '🥇 9شمش 99/9';

    const BTN_BAR_NADIR = '🥈 شمش نادیر';

    const BTN_BOT_ON = '🟢 روشن کردن ربات';

    const BTN_BOT_OFF = '🔴 خاموش کردن ربات';

    /** متن دکمه‌های منو که ادمین حتی وقتی ربات خاموش است باید بتواند بزند */
    protected array $adminAllowedTexts = [
        self::BTN_BOT_ON, self::BTN_BOT_OFF,
    ];

    /** callbackهایی که ادمین حتی وقتی ربات خاموش است می‌تواند بزند (زیرمنوی شمش) */
    protected array $adminAllowed = [
        'bar_999_unavailable', 'bar_999_set_price',
        'bar_nadir_unavailable', 'bar_nadir_set_price',
    ];

    public function home()
    {
        return response('✅ Bot Webhook is running!', 200);
    }

    public function setWebhook(TelegramClient $tg)
    {
        $url = rtrim(config('telegram.webhook_url'), '/').'/'.config('telegram.token');
        $result = $tg->setWebhook($url);

        return response("Webhook set to: {$url} — result: ".$result->body(), 200);
    }

    public function webhook(Request $request, string $token, TelegramClient $tg)
    {
        if ($token !== config('telegram.token')) {
            abort(404);
        }

        try {
            $update = $request->json()->all();

            $userId = null;
            $text = null;
            $callbackData = null;

            if (isset($update['message'])) {
                $userId = $update['message']['from']['id'] ?? null;
                $text = isset($update['message']['text']) ? trim($update['message']['text']) : '';
            } elseif (isset($update['callback_query'])) {
                $userId = $update['callback_query']['from']['id'] ?? null;
                $callbackData = $update['callback_query']['data'] ?? null;
            }

            $admins = config('telegram.admins');

            // اجازه‌ی ویژه به ادمین حتی وقتی ربات خاموش است
            if (in_array($userId, $admins, true)) {
                if (
                    $text === '/start'
                    || in_array($text, $this->adminAllowedTexts, true)
                    || in_array($callbackData, $this->adminAllowed, true)
                ) {
                    $this->processUpdate($update, $tg);

                    return response('ok', 200);
                }
            }

            // ربات خاموش است
            if (! SilverService::isBotActive()) {
                if (isset($update['callback_query'])) {
                    $tg->answerCallbackQuery($update['callback_query']['id'], '🔴 ربات خاموش است', true);
                }

                return response('ok', 200);
            }

            $this->processUpdate($update, $tg);

            return response('ok', 200);
        } catch (\Throwable $e) {
            Log::error('🔥 WEBHOOK ERROR: '.$e->getMessage()."\n".$e->getTraceAsString());

            return response('error', 500);
        }
    }

    protected function processUpdate(array $update, TelegramClient $tg): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query'], $tg);
        } elseif (isset($update['message']['text'])) {
            $text = trim($update['message']['text']);
            if ($text === '/start') {
                $this->handleStart($update['message'], $tg);
            } else {
                $this->handleText($update['message'], $tg);
            }
        }
    }

    // ---------- /start ----------
    protected function handleStart(array $message, TelegramClient $tg): void
    {
        $welcome = "سلام 👋\n\nبه ربات قیمت نقره خوش آمدید 🥈\nیکی از گزینه‌های زیر را انتخاب کنید:";

        $tg->sendMessage($message['chat']['id'], $welcome, $this->mainKeyboard());
    }

    /** کیبورد ثابت پایین صفحه (Reply Keyboard) به‌جای دکمه‌های inline */
    protected function mainKeyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => self::BTN_PRICE]],
                [['text' => self::BTN_PRICE_995]],
                [['text' => self::BTN_PERCENT]],
                [['text' => self::BTN_BAR_999]],
                [['text' => self::BTN_BAR_NADIR]],
                [['text' => self::BTN_BOT_ON]],
                [['text' => self::BTN_BOT_OFF]],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    // ---------- callback دکمه‌ها ----------
    protected function handleCallback(array $query, TelegramClient $tg): void
    {
        $userId = $query['from']['id'];
        $chatId = $query['message']['chat']['id'];
        $messageId = $query['message']['message_id'];
        $data = $query['data'] ?? '';
        $admins = config('telegram.admins');
        $isAdmin = in_array($userId, $admins, true);

        $tg->answerCallbackQuery($query['id']);

        $edit = fn (string $t, ?array $kb = null) => $tg->editMessageText($chatId, $messageId, $t, $kb);

        switch ($data) {
            case 'bar_999_unavailable':
                if (! $isAdmin) {
                    $edit('❌ دسترسی رد شد');
                } else {
                    SilverService::setBarStatus('bar_999', 'unavailable');
                    $edit('✅ شمش 999 → عدم موجودی ثبت شد.');
                }
                break;

            case 'bar_999_set_price':
                if (! $isAdmin) {
                    $edit('❌ دسترسی رد شد');
                } else {
                    $this->setState($userId, 'bar_999');
                    $edit("🥇 قیمت شمش 999 را به تومان وارد کنید:\n\nمثال: 45000000");
                }
                break;

            case 'bar_nadir_unavailable':
                if (! $isAdmin) {
                    $edit('❌ دسترسی رد شد');
                } else {
                    SilverService::setBarStatus('bar_nadir', 'unavailable');
                    $edit('✅ شمش نادیر → عدم موجودی ثبت شد.');
                }
                break;

            case 'bar_nadir_set_price':
                if (! $isAdmin) {
                    $edit('❌ دسترسی رد شد');
                } else {
                    $this->setState($userId, 'bar_nadir');
                    $edit("🥈 قیمت شمش نادیر را به تومان وارد کنید:\n\nمثال: 38000000");
                }
                break;
        }
    }

    // ---------- پیام‌های متنی (ورود قیمت) ----------
    protected function handleText(array $message, TelegramClient $tg): void
    {
        $userId = $message['from']['id'];
        $chatId = $message['chat']['id'];
        $text = trim($message['text']);
        $admins = config('telegram.admins');
        $isAdmin = in_array($userId, $admins, true);
        $state = $this->getState($userId);

        $reply = fn (string $t, ?array $kb = null) => $tg->sendMessage($chatId, $t, $kb, 'HTML');

        // فشردن یکی از دکمه‌های منوی ثابت (Reply Keyboard) همیشه اول بررسی می‌شود
        if ($this->handleMenuButton($text, $userId, $isAdmin, $reply)) {
            return;
        }

        // درصد خرید
        if ($state === 'percent') {
            if (! $isAdmin) {
                $reply('❌ فقط ادمین مجاز است');
                $this->clearState($userId);

                return;
            }
            if (! SilverService::isBotActive()) {
                $reply('🔴 ربات خاموش است');
                $this->clearState($userId);

                return;
            }
            $clean = str_replace(['٪', '%', '،'], '', $this->normalizeDigits($text));
            if (! is_numeric(trim($clean))) {
                $reply('❌ عدد معتبر وارد کنید');

                return;
            }
            $p = (float) trim($clean);
            SilverService::setBuyPercent($p);
            $factor = number_format(1 - $p / 100, 3, '.', '');
            $reply("✅ درصد خرید تنظیم شد: {$p}٪\nضریب خرید: ×{$factor}");
            $this->clearState($userId);

            return;
        }

        // شمش 999
        if ($state === 'bar_999') {
            if ($guard = $this->guard($isAdmin, $userId, $reply)) {
                return;
            }
            $clean = $this->digitsOnly($text);
            if ($clean === '') {
                $reply('❌ لطفاً عدد معتبر وارد کنید');

                return;
            }
            SilverService::setBarStatus('bar_999', $clean);
            $reply('✅ قیمت شمش 999 ثبت شد: '.SilverService::formatPrice((int) $clean).' تومان');
            $this->clearState($userId);

            return;
        }

        // شمش نادیر
        if ($state === 'bar_nadir') {
            if ($guard = $this->guard($isAdmin, $userId, $reply)) {
                return;
            }
            $clean = $this->digitsOnly($text);
            if ($clean === '') {
                $reply('❌ لطفاً عدد معتبر وارد کنید');

                return;
            }
            SilverService::setBarStatus('bar_nadir', $clean);
            $reply('✅ قیمت شمش نادیر ثبت شد: '.SilverService::formatPrice((int) $clean).' تومان');
            $this->clearState($userId);

            return;
        }

        // قیمت نقره 995
        if ($state === 'silver_995') {
            if ($guard = $this->guard($isAdmin, $userId, $reply)) {
                return;
            }
            $clean = $this->digitsOnly($text);
            if ($clean === '') {
                $reply('❌ لطفاً عدد معتبر وارد کنید');

                return;
            }
            $gram995 = (int) $clean;
            SilverService::setBarStatus('silver_995', $gram995);

            $last = SilverService::getLastRecordFull();
            if (! $last || ! $last->gram_price) {
                $this->clearState($userId);
                $reply('✅ قیمت نقره 995 ثبت شد: '.SilverService::formatPrice($gram995)." تومان\n⚠️ هنوز قیمت گرم 999/9 ثبت نشده — بعد از ثبت آن به کانال ارسال می‌شود.");

                return;
            }

            $this->fetchAndStore($last->gram_price, $reply);
            $this->clearState($userId);

            return;
        }

        // قیمت گرم 999/9
        if ($state === 'price') {
            if ($guard = $this->guard($isAdmin, $userId, $reply)) {
                return;
            }
            $clean = $this->digitsOnly($text);
            if ($clean === '') {
                $reply('❌ لطفاً عدد معتبر وارد کنید');

                return;
            }
            $this->fetchAndStore((int) $clean, $reply);
            $this->clearState($userId);

            return;
        }

        // پیش‌فرض
        $reply('ℹ️ از منوی پایین یکی از گزینه‌ها را انتخاب کنید');
    }

    /**
     * بررسی می‌کند آیا متن، یکی از دکمه‌های منوی ثابت است؛ اگر بود همان‌جا پاسخ می‌دهد.
     * true یعنی پردازش شد و handleText باید همان‌جا برگردد.
     */
    protected function handleMenuButton(string $text, $userId, bool $isAdmin, callable $reply): bool
    {
        $menuTexts = [
            self::BTN_PRICE, self::BTN_PRICE_995, self::BTN_PERCENT,
            self::BTN_BAR_999, self::BTN_BAR_NADIR, self::BTN_BOT_ON, self::BTN_BOT_OFF,
        ];

        if (! in_array($text, $menuTexts, true)) {
            return false;
        }

        $this->clearState($userId);

        if (! $isAdmin) {
            $reply('❌ دسترسی رد شد');

            return true;
        }

        if ($text === self::BTN_BOT_ON) {
            SilverService::setBotActive(true);
            $reply('🟢 ربات با موفقیت روشن شد');

            return true;
        }

        if ($text === self::BTN_BOT_OFF) {
            SilverService::setBotActive(false);
            $reply('🔴 ربات با موفقیت خاموش شد');

            return true;
        }

        if (! SilverService::isBotActive()) {
            $reply("🔴 ربات خاموش است\nابتدا ربات را روشن کنید.");

            return true;
        }

        switch ($text) {
            case self::BTN_PRICE:
                $this->setState($userId, 'price');
                $reply("✅ لطفاً قیمت گرم نقره را به تومان وارد کنید:\n\nمثال: 7500000");
                break;

            case self::BTN_PRICE_995:
                $this->setState($userId, 'silver_995');
                $reply("⚖️ لطفاً قیمت گرم نقره عیار 99.5 را به تومان وارد کنید:\n\nمثال: 7300000");
                break;

            case self::BTN_PERCENT:
                $this->setState($userId, 'percent');
                $reply("📉 درصد خرید را وارد کنید\nمثال:\n1 → ×0.99\n1.5 → ×0.985");
                break;

            case self::BTN_BAR_999:
                $reply('🥇 شمش 999 — یکی را انتخاب کنید:', ['inline_keyboard' => [
                    [['text' => '❌ عدم موجودی', 'callback_data' => 'bar_999_unavailable']],
                    [['text' => '💰 تعیین قیمت', 'callback_data' => 'bar_999_set_price']],
                ]]);
                break;

            case self::BTN_BAR_NADIR:
                $reply('🥈 شمش نادیر — یکی را انتخاب کنید:', ['inline_keyboard' => [
                    [['text' => '❌ عدم موجودی', 'callback_data' => 'bar_nadir_unavailable']],
                    [['text' => '💰 تعیین قیمت', 'callback_data' => 'bar_nadir_set_price']],
                ]]);
                break;
        }

        return true;
    }

    /**
     * قیمت‌های لحظه‌ای را می‌گیرد، رکورد را درج و پیام تأیید به ادمین می‌فرستد.
     * (مشترک بین حالت price و silver_995)
     */
    protected function fetchAndStore(int $gramPrice, callable $reply): void
    {
        $fetcher = new PriceFetcher();
        $tether = $fetcher->tether();
        $dollar = $fetcher->dollar();
        $silver = $fetcher->silverOunce();
        $dirham = $fetcher->dirham();
        $euro = $fetcher->euro();

        if ($dollar === null || $silver === null) {
            $reply('❌ خطا در دریافت قیمت ارز یا انس نقره');

            return;
        }

        $bar999 = SilverService::getBarPrice('bar_999');
        $barNadir = SilverService::getBarPrice('bar_nadir');
        $gram995 = SilverService::getBarPrice('silver_995');

        $r = SilverService::insertRecord(
            $gramPrice, $dollar, $tether, $silver, $dirham, $euro, $gram995, $bar999, $barNadir
        );

        $f = fn ($n) => SilverService::formatPrice($n);
        $bar999Str = $bar999 !== null ? $f($bar999) : 'عدم موجودی';
        $barNadirStr = $barNadir !== null ? $f($barNadir) : 'عدم موجودی';
        $now = Jalalian::now()->format('Y/m/d H:i');

        $reply(
            "✅ مثقال 999/9:\n".
            "🔴 مثقال فروش: {$f($r['mithqal_price'])} تومان\n".
            "🟢 مثقال خرید: {$f($r['mithqal_price_buy'])} تومان\n\n".
            "⚖️ گرم 999/9:\n".
            "🔴 فروش: {$f($gramPrice)} تومان\n".
            "🟢 خرید: {$f($r['gram_price_buy'])} تومان\n\n".
            "✅  مثقال995: \n".
            "🔴 مثقال فروش: {$f($r['mithqal_995_price'])} تومان\n".
            "🟢 مثقال خرید: {$f($r['mithqal_995_price_buy'])} تومان\n\n".
            "⚖️ گرم 995:\n".
            "🔴 فروش: {$f($gram995)} تومان\n".
            "🟢 خرید: {$f($r['gram_995_buy'])} تومان\n\n".
            "🥇 شمش 999   : {$bar999Str} تومان\n".
            "🥈 شمش نادیر : {$barNadirStr} تومان\n\n".
            "📅 {$now}"
        );
    }

    /** گارد مشترک ادمین/خاموشی. اگر باید برگردیم true می‌دهد. */
    protected function guard(bool $isAdmin, $userId, callable $reply): bool
    {
        if (! $isAdmin) {
            $reply('❌ فقط ادمین مجاز است');
            $this->clearState($userId);

            return true;
        }
        if (! SilverService::isBotActive()) {
            $reply('🔴 ربات خاموش است');
            $this->clearState($userId);

            return true;
        }

        return false;
    }

    // ---------- state گفت‌وگو (جای context.user_data پایتون) ----------
    protected function stateKey($userId): string
    {
        return "tg:state:{$userId}";
    }

    protected function setState($userId, string $state): void
    {
        Cache::put($this->stateKey($userId), $state, now()->addMinutes(10));
    }

    protected function getState($userId): ?string
    {
        return Cache::get($this->stateKey($userId));
    }

    protected function clearState($userId): void
    {
        Cache::forget($this->stateKey($userId));
    }

    // ---------- کمکی اعداد ----------
    protected function normalizeDigits(string $s): string
    {
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($ar, $en, str_replace($fa, $en, $s));
    }

    protected function digitsOnly(string $s): string
    {
        return preg_replace('/\D/', '', $this->normalizeDigits($s));
    }
}
