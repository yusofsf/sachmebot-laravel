<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * گرفتن قیمت‌ها از منابع خارجی:
 *  - تتر: Nobitex
 *  - دلار/درهم/یورو: alanchand.com (scrape)
 *  - انس نقره: Yahoo Finance (SI=F)
 */
class PriceFetcher
{
    /** قیمت آخرین معامله‌ی تتر (تومان) از Nobitex */
    public function tether(): ?int
    {
        try {
            $resp = Http::withOptions([
                'curl' => [
                    CURLOPT_INTERFACE => '62.60.211.91',
                ],
            ])->timeout(10)->get('https://apiv2.nobitex.ir/v3/orderbook/USDTIRT');
            $data = $resp->json();

            if (($data['status'] ?? null) === 'ok' && isset($data['lastTradePrice'])) {
                $price = (int) $data['lastTradePrice'];
                $price = (int) (round($price / 10) * 10);
                // فقط ۶ رقم اول
                $price = (int) substr((string) $price, 0, 6);

                return $price;
            }
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

    /** انس نقره به دلار از Yahoo Finance (با اصلاح ۱.۰۰۱) */
    public function silverOunce(): ?float
    {
        try {
            $resp = Http::withOptions([
                'curl' => [
                    CURLOPT_INTERFACE => '62.60.211.91',
                ],
            ])->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
                ->timeout(10)
                ->get('https://query2.finance.yahoo.com/v8/finance/chart/SI=F', [
                    'interval' => '1m',
                    'range' => '1d',
                ]);

            $data = $resp->json();
            $meta = $data['chart']['result'][0]['meta'] ?? null;

            if ($meta && isset($meta['regularMarketPrice'])) {
                return (float) $meta['regularMarketPrice'] * 1.001;
            }
        } catch (\Throwable $e) {
            Log::error('silver ounce fetch failed: '.$e->getMessage());
        }

        return null;
    }

    /** قیمت فروش یک ردیف از جدول alanchand بر اساس کلیدواژه‌ی ستون اول */
    protected function alanchand(string $keyword): ?float
    {
        try {
            $resp = Http::withOptions([
                'curl' => [
                    CURLOPT_INTERFACE => '62.60.211.91',
                ],
            ])->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
                ->timeout(10)
                ->get('https://alanchand.com/');

            $html = $resp->body();
            if ($html === '') {
                return null;
            }

            $doc = new \DOMDocument();
            libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
            libxml_clear_errors();

            $xp = new \DOMXPath($doc);
            foreach ($xp->query('//table//tr') as $tr) {
                $cells = $xp->query('.//td', $tr);
                if ($cells->length >= 3) {
                    $first = trim($cells->item(0)->textContent);
                    if (mb_strpos($first, $keyword) !== false) {
                        // قیمت فروش (ستون سوم) — alanchand ارقام فارسی می‌دهد
                        $sell = $this->normalizeDigits(trim($cells->item(2)->textContent));
                        $sell = str_replace([',', '،', ' ', "\u{00a0}", "\u{200f}", "\u{200e}"], '', $sell);
                        if (is_numeric($sell)) {
                            return (float) $sell;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error("alanchand fetch failed ($keyword): ".$e->getMessage());
        }

        return null;
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
