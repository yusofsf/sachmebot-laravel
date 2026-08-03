<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class WarmBot extends Command
{
    protected $signature = 'bot:warmup';

    protected $description = 'Keep the web worker warm to avoid webhook cold starts';

    public function handle(): int
    {
        if (! config('telegram.warmup_enabled', true)) {
            return self::SUCCESS;
        }

        $url = rtrim(
            (string) config('telegram.warmup_url', config('app.url')),
            '/'
        ).'/';

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error('Invalid BOT_WARMUP_URL: '.$url);

            return self::FAILURE;
        }

        $startedAt = microtime(true);

        try {
            $response = Http::withOptions([
                'curl' => [
                    CURLOPT_INTERFACE => config(
                        'telegram.outbound_interface',
                        '62.60.211.91'
                    ),
                ],
            ])
                ->connectTimeout(2)
                ->timeout(5)
                ->get($url);

            if (! $response->successful()) {
                $this->error("Warm-up returned HTTP {$response->status()}");

                return self::FAILURE;
            }

            $elapsed = (int) round((microtime(true) - $startedAt) * 1000);
            $this->info("Worker warmed in {$elapsed}ms");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Warm-up failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
