<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * رپر سبک روی Telegram Bot API (جایگزین python-telegram-bot).
 */
class TelegramClient
{
    protected string $base;

    public function __construct()
    {
        $this->base = 'https://api.telegram.org/bot'.config('telegram.token').'/';
    }

    public function sendMessage($chatId, string $text, ?array $replyMarkup = null, string $parseMode = 'HTML')
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        return Http::asForm()->post($this->base.'sendMessage', $payload);
    }

    public function editMessageText($chatId, $messageId, string $text, ?array $replyMarkup = null)
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        return Http::asForm()->post($this->base.'editMessageText', $payload);
    }

    public function answerCallbackQuery($callbackQueryId, ?string $text = null, bool $showAlert = false)
    {
        $payload = [
            'callback_query_id' => $callbackQueryId,
            'show_alert' => $showAlert ? 'true' : 'false',
        ];
        if ($text !== null) {
            $payload['text'] = $text;
        }

        return Http::asForm()->post($this->base.'answerCallbackQuery', $payload);
    }

    public function setWebhook(string $url)
    {
        return Http::get($this->base.'setWebhook', ['url' => $url]);
    }
}
