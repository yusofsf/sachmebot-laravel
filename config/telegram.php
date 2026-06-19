<?php

return [
    // توکن ربات از BotFather
    'token' => env('BOT_TOKEN', ''),

    // آدرس عمومی اپ؛ وب‌هوک روی WEBHOOK_URL/TOKEN ست می‌شود
    'webhook_url' => env('WEBHOOK_URL', ''),

    // کانال مقصد ارسال قیمت‌ها
    'channel' => env('TELEGRAM_CHANNEL', '@sachme_kaf'),

    // آیدی عددی ادمین‌ها (با کاما جدا)
    'admins' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('TELEGRAM_ADMINS', '271469412'))
    ))),

    // ساعت ارسال گزارش روزانه (به وقت APP_TIMEZONE)
    'report_hour' => 20,
    'report_minute' => 5,

    // بازه‌ی کاری برای ارسال خودکار قیمت
    'work_start_hour' => 10,
    'work_end_hour' => 20,
];
