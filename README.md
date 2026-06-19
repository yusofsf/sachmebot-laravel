# sachme-laravel — ربات تلگرام قیمت نقره (نسخه‌ی Laravel)

پورت کامل ربات نقره‌ی کانال **@sachme_kaf** از Python/Flask + `python-telegram-bot`
به **Laravel 11**. همان منطق، همان دیتابیس (SQLite)، همان پیام‌ها — فقط با PHP.

ادمین قیمت گرم نقره را وارد می‌کند؛ ربات مثقال، حباب و قیمت خرید را حساب کرده،
در SQLite ذخیره و به کانال ارسال می‌کند. ارز/انس از منابع آنلاین گرفته می‌شود.

---

## معماری و نگاشت با نسخه‌ی پایتون

| پایتون | Laravel | توضیح |
|--------|---------|-------|
| `main.py` (Flask routes) | `app/Http/Controllers/TelegramWebhookController.php` | وب‌هوک، `/start`، callbackها، ورود قیمت |
| `services.py` | `app/Services/SilverService.php` | محاسبه و درج رکورد، تنظیمات، وضعیت شمش |
| توابع `fetch_*` | `app/Services/PriceFetcher.php` | تتر (Nobitex)، دلار/درهم/یورو (alanchand)، انس (Yahoo) |
| `build_message` / `build_daily_report` | `app/Services/MessageBuilder.php` | متن پیام کانال و گزارش روزانه |
| `python-telegram-bot` | `app/Services/TelegramClient.php` | فراخوانی Bot API با Http |
| `fetch-prices.py` | `php artisan bot:fetch-prices` | کرون به‌روزرسانی قیمت |
| `send-daily.py` | `php artisan bot:send-daily` | کرون گزارش روزانه |
| جداول SQLite | `database/migrations/*` | `silver_prices`، `bot_settings`، `bar_status` |
| `context.user_data` | Laravel **Cache** (کلید `tg:state:{userId}`) | نگه‌داری state گفت‌وگوی ادمین |

> نکته: در Flask، state ورود قیمت در حافظه‌ی فرایند بود؛ چون وب‌هوک بی‌حالت است،
> اینجا state ادمین در Cache (درایور file) با انقضای ۱۰ دقیقه نگه‌داری می‌شود.

---

## پیش‌نیاز
- PHP **8.2+** با اکستنشن‌های `pdo_sqlite`, `dom`, `mbstring`, `curl`
- Composer

## راه‌اندازی محلی
```bash
cd D:\project\shachme-laravel
composer install
copy .env.example .env        # لینوکس: cp .env.example .env
php artisan key:generate
php artisan migrate            # جداول + مقادیر پیش‌فرض (is_active=1, buy_percent=3)
php artisan serve             # http://127.0.0.1:8000
```
آدرس `/` باید بدهد: `✅ Bot Webhook is running!`

> فایل `database/database.sqlite` از قبل (خالی) هست؛ `migrate` آن را پر می‌کند.

## تست بدون تلگرام
چون وب‌هوک است، می‌توانی یک آپدیت ساختگی POST کنی:
```bash
curl -X POST "http://127.0.0.1:8000/<BOT_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"update_id":1,"message":{"message_id":1,"date":0,"chat":{"id":271469412,"type":"private"},"from":{"id":271469412,"is_bot":false},"text":"/start"}}'
```

## اجرای دستی کرون‌ها
```bash
php artisan bot:fetch-prices   # فقط ساعت ۱۰ تا ۲۰ به وقت تهران کار می‌کند
php artisan bot:send-daily
```

---

## استقرار

### روی سرور با دسترسی SSH (پیشنهادی)
1. کل پروژه را آپلود کن، بعد `composer install --no-dev --optimize-autoloader`.
2. `.env` را بساز (`APP_KEY` با `php artisan key:generate`)، `BOT_TOKEN` و بقیه را پر کن.
3. `php artisan migrate --force`.
4. وب‌سرور را به پوشه‌ی `public/` اشاره بده (DocumentRoot).
5. وب‌هوک تلگرام را ثبت کن: یک‌بار `https://<دامنه>/setwebhook` را باز کن.
6. زمان‌بند Laravel را با **یک** کرون سیستم فعال کن:
   ```cron
   * * * * * cd /path/to/shachme-laravel && php artisan schedule:run >> /dev/null 2>&1
   ```
   بقیه‌ی زمان‌بندی (`bot:fetch-prices` هر ۵ دقیقه ۱۰–۲۰، `bot:send-daily` ساعت ۲۰:۰۵)
   داخل `routes/console.php` تعریف شده است.

### روی cPanel (Passenger/Apache)
- DocumentRoot دامنه را به `public/` بده، یا اگر `public_html` ثابت است محتویات
  `public/` را آنجا بگذار و در `index.php` مسیر `__DIR__.'/../...'` را به محل پروژه اصلاح کن.
- اگر SSH نداری، `vendor/` را محلی بساز و کامل آپلود کن.
- برای کرون می‌توانی به‌جای `schedule:run` مستقیماً دستورها را زمان‌بندی کنی:
  ```
  */5 10-19 * * * cd /home/USER/shachme-laravel && php artisan bot:fetch-prices
  5 20 * * *      cd /home/USER/shachme-laravel && php artisan bot:send-daily
  ```

---

## تنظیمات محیط (`.env`)
| کلید | توضیح |
|------|-------|
| `BOT_TOKEN` | توکن ربات از BotFather |
| `WEBHOOK_URL` | آدرس عمومی اپ؛ وب‌هوک روی `WEBHOOK_URL/BOT_TOKEN` ست می‌شود |
| `TELEGRAM_CHANNEL` | کانال مقصد (پیش‌فرض `@sachme_kaf`) |
| `TELEGRAM_ADMINS` | آیدی عددی ادمین‌ها با کاما (پیش‌فرض `271469412`) |
| `APP_TIMEZONE` | باید `Asia/Tehran` باشد تا بازه‌ی کاری و گزارش درست باشد |

## فرمول‌ها (بدون تغییر نسبت به پایتون)
- `مثقال = گرم / 0.217`
- `ضریب خرید = 1 - درصد/100`
- `حباب مثقال = مثقال - (دلار × انس / 6.75)` و `حباب گرم = حباب مثقال × 0.217`

## نکات
- پیام‌های فارسی با `\u{200F}` (RTL mark) ساخته می‌شوند تا چینش راست‌به‌چپ درست بماند.
- تاریخ شمسی با پکیج `morilog/jalali`.
- اسکرپ `alanchand.com` با `DOMDocument` انجام می‌شود؛ اگر ساختار سایت عوض شود باید
  `PriceFetcher::alanchand()` به‌روز شود (مثل نسخه‌ی پایتون که به HTML سایت وابسته بود).
