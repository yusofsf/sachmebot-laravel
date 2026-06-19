<?php

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [TelegramWebhookController::class, 'home']);
Route::get('/setwebhook', [TelegramWebhookController::class, 'setWebhook']);

// وب‌هوک تلگرام: POST به /{token} (مثل اپ پایتون). توکن داخل کنترلر بررسی می‌شود.
Route::post('/{token}', [TelegramWebhookController::class, 'webhook'])
    ->where('token', '.*');
