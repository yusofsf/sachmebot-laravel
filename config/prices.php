<?php

return [
    /*
    | دیتابیس SQLite سایت طلا. پسوند فایل مهم نیست و می‌تواند .sql باشد.
    | برای محیط‌های دیگر این مقدار را با GOLD_DATABASE_PATH تغییر دهید.
    */
    'gold_database_path' => env(
        'GOLD_DATABASE_PATH',
        '/home/metalspi/talaborad-laravel/database/database.sql'
    ),

    /* خالی باشد، جدول بر اساس نام ستون‌های قیمت به‌صورت خودکار پیدا می‌شود. */
    'gold_price_table' => env('GOLD_PRICE_TABLE'),
];
