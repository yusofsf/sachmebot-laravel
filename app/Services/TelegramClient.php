<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * رپر سبک روی Telegram Bot API (جایگزین python-telegram-bot).
 */
class TelegramClient
{
    protected string $base;

    protected PendingRequest $http;

    public function __construct()
    {
        $this->base = 'https://api.telegram.org/bot'.config('telegram.token').'/';

        $this->http = Http::baseUrl($this->base)
            ->withOptions([
                'curl' => [
                    CURLOPT_INTERFACE => config(
                        'telegram.outbound_interface',
                        '62.60.211.91'
                    ),
                ],
            ])
            ->asForm()
            ->acceptJson()
            ->connectTimeout((int) config('telegram.connect_timeout', 2))
            ->timeout((int) config('telegram.timeout', 6))
            ->retry(2, 250);

        // Reuse the TCP/TLS connection for consecutive Bot API calls.
        $this->http->setClient($this->http->buildClient());
    }

    public function sendMessage($chatId, string $text, ?array $replyMarkup = null, string $parseMode = 'HTML'): Response
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        try {
            $response = $this->http->post('sendMessage', $payload);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Telegram connection failed: '.$this->sanitizeError($e->getMessage()),
                0,
                $e
            );
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            $description = $response->json('description');
            $description = is_string($description)
                ? $this->sanitizeError($description)
                : 'No error description returned';

            throw new RuntimeException(
                "Telegram API rejected sendMessage (HTTP {$response->status()}): {$description}"
            );
        }

        return $response;
    }

    private function sanitizeError(string $message): string
    {
        $token = (string) config('telegram.token');

        if ($token !== '') {
            $message = str_replace($token, '[REDACTED]', $message);
        }

        return preg_replace('/bot\d+:[A-Za-z0-9_-]+/', 'bot[REDACTED]', $message) ?? $message;
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

        return $this->http->post('editMessageText', $payload);
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

        return $this->http->post('answerCallbackQuery', $payload);
    }

    public function setWebhook(string $url)
    {
        return $this->http->get('setWebhook', ['url' => $url]);
    }
}
