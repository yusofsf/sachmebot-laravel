<?php

namespace App\Console\Commands;

use App\Services\PriceFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * تشخیص: هر منبع قیمت و دسترسی شبکه‌ی سرور را جداگانه تست می‌کند.
 */
class TestFetch extends Command
{
    protected $signature = 'bot:test-fetch';

    protected $description = 'Diagnose each price source and raw connectivity';

    public function handle(): int
    {
        $f = new PriceFetcher();

        $this->info('== نتیجه‌ی fetcherها ==');
        $this->line('tether      = '.var_export($f->tether(), true));
        $this->line('dollar      = '.var_export($f->dollar(), true));
        $this->line('dirham      = '.var_export($f->dirham(), true));
        $this->line('euro        = '.var_export($f->euro(), true));
        $this->line('silverOunce = '.var_export($f->silverOunce(), true));

        $this->info(PHP_EOL.'== دسترسی خام شبکه ==');
        $this->probe('Nobitex', 'https://apiv2.nobitex.ir/v3/orderbook/USDTIRT');
        $this->probe('alanchand', 'https://alanchand.com/');
        $this->probe('Yahoo', 'https://query2.finance.yahoo.com/v8/finance/chart/SI=F?interval=1m&range=1d');
        $this->probe('TGJU api', 'https://api.tgju.org/v1/data/sana/json');
        $this->probe('TGJU silver', 'https://www.tgju.org/profile/silver');

        return self::SUCCESS;
    }

    protected function probe(string $name, string $url): void
    {
        try {
            $r = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
                ->timeout(15)->get($url);
            $this->line(sprintf('%-12s status=%d bytes=%d', $name, $r->status(), strlen($r->body())));
        } catch (\Throwable $e) {
            $this->error(sprintf('%-12s EXCEPTION: %s', $name, $e->getMessage()));
        }
    }
}
