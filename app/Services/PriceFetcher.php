<?php

namespace App\Services;

use App\Services\GoldPriceService;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * گرفتن قیمت‌ها از منابع خارجی:
 *  - تتر: Nobitex
 *  - دلار/درهم/یورو: alanchand.com (scrape)
 *  - انس نقره: از دیتابیس talaborad خوانده می‌شود (در GoldPriceService)
 */
class PriceFetcher
{
    private const INTERFACE_IP = '62.60.211.91';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';

    protected ?string $alanchandHtml = null;

    protected bool $alanchandFetched = false;

    protected ?string $tgjuHtml = null;

    protected ?\DOMXPath $tgjuXpath = null;

    /**
     * دریافت هم‌زمان منابع اصلی؛ TGJU فقط در صورت ناموفق بودن alanchand فراخوانی می‌شود.
     *
     * @return array{tether:?int,dollar:?float,silver:?float,dirham:?float,euro:?float}
     */
    public function fetchAll(): array
    {
        try {
            $responses = Http::pool(fn (Pool $pool) => [
                $pool->as('tether')
                    ->withOptions($this->curlOptions())
                    ->timeout(10)
                    ->get('https://apiv2.nobitex.ir/v3/orderbook/USDTIRT'),
                $pool->as('alanchand')
                    ->withOptions($this->curlOptions())
                    ->withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout(10)
                    ->get('https://alanchand.com/'),
            ]);

            $this->alanchandFetched = true;
            $this->alanchandHtml = $responses['alanchand'] instanceof Response
                ? $responses['alanchand']->body()
                : null;

            $dollar = $this->parseAlanchand('دلار');
            $dirham = $this->parseAlanchand('درهم');
            $euro = $this->parseAlanchand('یورو');

            // Do not make every webhook wait for the slow fallback source. Fetch
            // it only when at least one value is actually missing.
            if ($dollar === null || $dirham === null || $euro === null) {
                $this->fetchTgju();
            }

            return [
                'tether' => $responses['tether'] instanceof Response
                    ? $this->parseTether($responses['tether'])
                    : null,
                'dollar' => $dollar
                    ?? $this->parseTgju(['price_dollar_rl', 'price_dollar_dt']),
                'silver' => null,
                'dirham' => $dirham
                    ?? $this->parseTgju(['price_aed', 'PRICE_AED']),
                'euro' => $euro
                    ?? $this->parseTgju(['price_eur']),
            ];
        } catch (\Throwable $e) {
            Log::error('parallel price fetch failed: '.$e->getMessage());

            return [
                'tether' => null,
                'dollar' => null,
                'silver' => null,
                'dirham' => null,
                'euro' => null,
            ];
        }
    }

    /** قیمت آخرین معامله‌ی تتر (تومان) از Nobitex */
    public function tether(): ?int
    {
        try {
            $response = Http::withOptions($this->curlOptions())
                ->timeout(10)
                ->get('https://apiv2.nobitex.ir/v3/orderbook/USDTIRT');

            return $this->parseTether($response);
        } catch (\Throwable $e) {
            Log::error('tether fetch failed: '.$e->getMessage());
        }

        return null;
    }

    public function dollar(): ?float
    {
        return $this->alanchand('دلار');
    }

    public function dirham(): ?float
    {
        return $this->alanchand('درهم');
    }

    public function euro(): ?float
    {
        return $this->alanchand('یورو');
    }

    /** انس نقره از آخرین رکورد دیتابیس talaborad. */
    public function silverOunce(): ?float
    {
        return (new GoldPriceService)->latest()['silver_ounce'] ?? null;
    }

    /** قیمت فروش یک ردیف از جدول alanchand بر اساس کلیدواژه‌ی ستون اول */
    protected function alanchand(string $keyword): ?float
    {
        try {
            if (! $this->alanchandFetched) {
                $response = Http::withOptions($this->curlOptions())
                    ->withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout(10)
                    ->get('https://alanchand.com/');

                $this->alanchandHtml = $response->body();
                $this->alanchandFetched = true;
            }

            return $this->parseAlanchand($keyword);
        } catch (\Throwable $e) {
            Log::error("alanchand fetch failed ($keyword): ".$e->getMessage());
        }

        return null;
    }

    protected function parseTether(Response $response): ?int
    {
        $data = $response->json();

        if (($data['status'] ?? null) !== 'ok' || ! isset($data['lastTradePrice'])) {
            return null;
        }

        $price = (int) $data['lastTradePrice'];
        $price = (int) (round($price / 10) * 10);

        return (int) substr((string) $price, 0, 6);
    }

    protected function parseSilverOunce(Response $response): ?float
    {
        $meta = $response->json('chart.result.0.meta');

        return isset($meta['regularMarketPrice'])
            ? (float) $meta['regularMarketPrice'] * 1.001
            : null;
    }

    /** مقدار «نرخ فعلی» صفحه‌ی پروفایل انس نقره‌ی TGJU. */
    protected function parseTgjuSilverOunce(Response $response): ?float
    {
        if (! $response->successful()) {
            return null;
        }

        return $this->parseTgjuSilverHtml($response->body());
    }

    protected function parseTgjuSilverHtml(string $html): ?float
    {
        if (trim($html) === '') {
            return null;
        }

        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        if (! $loaded) {
            return null;
        }

        $xpath = new \DOMXPath($doc);

        // TGJU marks the displayed current rate with this stable data field.
        foreach ($xpath->query('//*[@data-col="info.last_trade.PDrCotVal"]') as $node) {
            $price = $this->parseDecimal($node->textContent);

            if ($price !== null) {
                return $price;
            }
        }

        // Keep a label-based fallback for harmless markup changes on the page.
        foreach ($xpath->query('//tr[.//*[contains(normalize-space(.), "نرخ فعلی")]]') as $row) {
            $cells = $xpath->query('./th|./td', $row);

            for ($i = $cells->length - 1; $i >= 0; $i--) {
                $price = $this->parseDecimal($cells->item($i)->textContent);

                if ($price !== null) {
                    return $price;
                }
            }
        }

        return null;
    }

    /** Yahoo fallback؛ ضریب اصلاح قبلی حفظ شده است. */
    protected function fetchYahooSilverOunce(): ?float
    {
        try {
            $response = Http::withOptions($this->curlOptions())
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(10)
                ->get('https://query2.finance.yahoo.com/v8/finance/chart/SI=F', [
                    'interval' => '1m',
                    'range' => '1d',
                ]);

            return $this->parseSilverOunce($response);
        } catch (\Throwable $e) {
            Log::error('Yahoo silver ounce fallback failed: '.$e->getMessage());
        }

        return null;
    }

    protected function parseDecimal(string $value): ?float
    {
        $value = $this->normalizeDigits(trim($value));
        $value = str_replace('٫', '.', $value);
        $value = str_replace([',', '،', '٬', ' ', "\u{00a0}", "\u{200f}", "\u{200e}"], '', $value);

        if (! preg_match('/-?\d+(?:\.\d+)?/', $value, $match)) {
            return null;
        }

        $price = (float) $match[0];

        return $price > 0 ? $price : null;
    }

    protected function parseAlanchand(string $keyword): ?float
    {
        if (empty($this->alanchandHtml)) {
            return null;
        }

        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$this->alanchandHtml);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);

        foreach ($xpath->query('//table//tr') as $row) {
            $cells = $xpath->query('.//td', $row);

            if ($cells->length < 3) {
                continue;
            }

            $first = trim($cells->item(0)->textContent);

            if (mb_strpos($first, $keyword) === false) {
                continue;
            }

            $sell = $this->normalizeDigits(trim($cells->item(2)->textContent));
            $sell = str_replace([',', '،', ' ', "\u{00a0}", "\u{200f}", "\u{200e}"], '', $sell);

            return is_numeric($sell) ? (float) $sell : null;
        }

        return null;
    }

    /** نرخ فعلی TGJU (ریال) → تومان */
    protected function parseTgju(array $marketRows): ?float
    {
        if (empty($this->tgjuHtml)) {
            return null;
        }

        if (! $this->tgjuXpath) {
            $doc = new \DOMDocument;
            libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="utf-8" ?>'.$this->tgjuHtml);
            libxml_clear_errors();
            $this->tgjuXpath = new \DOMXPath($doc);
        }

        foreach ($marketRows as $marketRow) {
            $query = sprintf(
                '//tr[@data-market-row="%s"]//td[contains(concat(" ", normalize-space(@class), " "), " nf ")][1]',
                $marketRow
            );
            $cell = $this->tgjuXpath->query($query)?->item(0);

            if (! $cell) {
                continue;
            }

            $price = $this->normalizeDigits(trim($cell->textContent));
            $price = str_replace([',', '،', ' ', "\u{00a0}", "\u{200f}", "\u{200e}"], '', $price);

            if (is_numeric($price)) {
                return (float) $price / 10;
            }
        }

        return null;
    }

    protected function fetchTgju(): void
    {
        try {
            $response = Http::withOptions($this->curlOptions())
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->connectTimeout(2)
                ->timeout(6)
                ->get('https://www.tgju.org/currency');

            $this->tgjuHtml = $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
            Log::warning('TGJU fallback fetch failed: '.$e->getMessage());
            $this->tgjuHtml = null;
        }
    }

    protected function curlOptions(): array
    {
        return [
            'curl' => [
                CURLOPT_INTERFACE => config(
                    'telegram.outbound_interface',
                    self::INTERFACE_IP
                ),
            ],
        ];
    }

    /** ارقام فارسی/عربی → انگلیسی */
    protected function normalizeDigits(string $s): string
    {
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($ar, $en, str_replace($fa, $en, $s));
    }
}
