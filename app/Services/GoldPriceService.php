<?php

namespace App\Services;

use App\Support\BotLog;
use Illuminate\Support\Carbon;

class GoldPriceService
{
    private const TROY_OUNCE_GRAMS = 31.1034768;

    private const GOLD_18K_PURITY = 0.75;

    /** ستون‌هایی که وجودشان جدول قیمت طلا را مشخص می‌کند. */
    private const REQUIRED_COLUMNS = [
        'bahar_sell', 'bahar_buy',
        'nim_sell', 'nim_buy',
        'rob_sell', 'rob_buy',
        'mithqal_sell', 'mithqal_buy',
        'geram_sell', 'geram_buy',
        'ounce',
    ];

    /** نام‌های جایگزین رایج در نسخه‌های مختلف دیتابیس سایت. */
    private const COLUMN_ALIASES = [
        'bahar_sell' => ['bahar_sell'],
        'bahar_buy' => ['bahar_buy'],
        'nim_sell' => ['nim_sell'],
        'nim_buy' => ['nim_buy'],
        'rob_sell' => ['rob_sell', 'rob_sel'],
        'rob_buy' => ['rob_buy'],
        'mithqal_sell' => ['mithqal_sell', 'mesghal_sell', 'misghal_sell'],
        'mithqal_buy' => ['mithqal_buy', 'mesghal_buy', 'misghal_buy'],
        'geram_sell' => ['geram_sell', 'gram_sell'],
        'geram_buy' => ['geram_buy', 'gram_buy'],
        'ounce' => ['ounce', 'ounce_sell', 'ons', 'ons_sell'],
        'silver_ounce' => ['silver_ounce'],
    ];

    /**
     * آخرین قیمت خرید و فروش طلا و سکه را از دیتابیس سایت می‌خواند.
     *
     * @return array<string, int|float>|null
     */
    public function latest(): ?array
    {
        $path = $this->databasePath((string) config('prices.gold_database_path'));

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            BotLog::warning('دیتابیس قیمت طلای سایت در دسترس نیست', ['path' => $path]);

            return null;
        }

        try {
            $pdo = new \PDO('sqlite:'.$path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA busy_timeout = 3000');

            $table = $this->findPriceTable($pdo);
            if ($table === null) {
                BotLog::warning('جدول قیمت طلا با ستون‌های مورد انتظار پیدا نشد', [
                    'path' => $path,
                    'required_columns' => self::REQUIRED_COLUMNS,
                    'database_schema' => $this->databaseSchema($pdo),
                ]);

                return null;
            }

            $columns = $this->tableColumns($pdo, $table);
            $columnMap = $this->resolveColumnMap($columns);
            $order = $this->latestOrder($columns);
            $selectedColumns = self::REQUIRED_COLUMNS;
            if (isset($columnMap['silver_ounce'])) {
                $selectedColumns[] = 'silver_ounce';
            }
            $quotedColumns = implode(', ', array_map(
                fn (string $canonical): string => sprintf(
                    '%s AS %s',
                    $this->quoteIdentifier($columnMap[$canonical]),
                    $this->quoteIdentifier($canonical)
                ),
                $selectedColumns
            ));
            $sql = sprintf(
                'SELECT %s FROM %s ORDER BY %s DESC LIMIT 1',
                $quotedColumns,
                $this->quoteIdentifier($table),
                $order
            );
            $row = $pdo->query($sql)->fetch();

            if (! is_array($row)) {
                return null;
            }

            $result = [];
            foreach (self::REQUIRED_COLUMNS as $column) {
                $value = $this->numericValue($row[$column] ?? null);
                if ($value === null) {
                    BotLog::warning('مقدار نامعتبر در قیمت طلای سایت', [
                        'table' => $table, 'column' => $column, 'value' => $row[$column] ?? null,
                    ]);

                    return null;
                }
                $result[$column] = $value;
            }

            $result['silver_ounce'] = isset($columnMap['silver_ounce'])
                ? $this->numericValue($row['silver_ounce'] ?? null)
                : null;

            return $result;
        } catch (\Throwable $e) {
            BotLog::warning('خواندن دیتابیس قیمت طلای سایت ناموفق بود', [
                'path' => $path,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * کمترین و بیشترین قیمت‌های امروز برای گزارش پایان روز.
     *
     * @return array<string, array{min:int|float|null,max:int|float|null}>|null
     */
    public function dailyStats(?Carbon $date = null): ?array
    {
        $path = $this->databasePath((string) config('prices.gold_database_path'));
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            BotLog::warning('دیتابیس قیمت طلای سایت برای گزارش روزانه در دسترس نیست', [
                'path' => $path,
            ]);

            return null;
        }

        try {
            $pdo = new \PDO('sqlite:'.$path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA busy_timeout = 3000');

            $table = $this->findPriceTable($pdo);
            if ($table === null) {
                BotLog::warning('جدول قیمت طلا برای گزارش روزانه پیدا نشد', [
                    'path' => $path,
                    'database_schema' => $this->databaseSchema($pdo),
                ]);

                return null;
            }

            $columns = $this->tableColumns($pdo, $table);
            $createdAt = $this->actualColumn($columns, 'created_at');
            if ($createdAt === null) {
                BotLog::warning('ستون created_at جدول قیمت طلا پیدا نشد', [
                    'table' => $table,
                    'columns' => $columns,
                ]);

                return null;
            }

            $columnMap = $this->resolveColumnMap($columns);
            $selects = [];
            foreach ($columnMap as $canonical => $actual) {
                $column = $this->quoteIdentifier($actual);
                $selects[] = sprintf('MIN(%s) AS %s', $column, $this->quoteIdentifier($canonical.'_min'));
                $selects[] = sprintf('MAX(%s) AS %s', $column, $this->quoteIdentifier($canonical.'_max'));
            }

            $sql = sprintf(
                'SELECT %s FROM %s WHERE DATE(%s) = :date',
                implode(', ', $selects),
                $this->quoteIdentifier($table),
                $this->quoteIdentifier($createdAt)
            );
            $statement = $pdo->prepare($sql);
            $statement->execute([
                'date' => ($date ?? Carbon::now(config('app.timezone')))->toDateString(),
            ]);
            $row = $statement->fetch();

            if (! is_array($row)) {
                return null;
            }

            $stats = [];
            $hasValue = false;
            foreach (self::REQUIRED_COLUMNS as $canonical) {
                $min = $this->numericValue($row[$canonical.'_min'] ?? null);
                $max = $this->numericValue($row[$canonical.'_max'] ?? null);
                $stats[$canonical] = ['min' => $min, 'max' => $max];
                $hasValue = $hasValue || $min !== null || $max !== null;
            }

            return $hasValue ? $stats : null;
        } catch (\Throwable $e) {
            BotLog::warning('خواندن آمار روزانه قیمت طلا ناموفق بود', [
                'path' => $path,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * حباب هر گرم طلای ۱۸ عیار بر اساس قیمت فروش بازار.
     *
     * @return array{intrinsic:float,bubble:float,percent:float}|null
     */
    public static function calculate18kBubble(
        int|float|null $marketGram18k,
        int|float|null $ounceUsd,
        int|float|null $dollarToman
    ): ?array {
        if ($marketGram18k === null || $ounceUsd === null || $dollarToman === null) {
            return null;
        }

        $marketGram18k = (float) $marketGram18k;
        $intrinsic = self::intrinsic18kGram($ounceUsd, $dollarToman);
        if ($marketGram18k <= 0 || $intrinsic === null) {
            return null;
        }

        $bubble = $marketGram18k - $intrinsic;

        return [
            'intrinsic' => $intrinsic,
            'bubble' => $bubble,
            'percent' => ($bubble / $intrinsic) * 100,
        ];
    }

    /**
     * حباب یک مثقال طلای ۱۸ عیار؛ هر مثقال مطابق محاسبات پروژه ۱÷۰٫۲۱۷ گرم است.
     *
     * @return array{intrinsic:float,bubble:float,percent:float}|null
     */
    public static function calculate18kMithqalBubble(
        int|float|null $marketMithqal18k,
        int|float|null $ounceUsd,
        int|float|null $dollarToman
    ): ?array {
        if ($marketMithqal18k === null || (float) $marketMithqal18k <= 0) {
            return null;
        }

        $intrinsicGram = self::intrinsic18kGram($ounceUsd, $dollarToman);
        if ($intrinsicGram === null) {
            return null;
        }

        $intrinsic = $intrinsicGram / 0.217;
        $bubble = (float) $marketMithqal18k - $intrinsic;

        return [
            'intrinsic' => $intrinsic,
            'bubble' => $bubble,
            'percent' => ($bubble / $intrinsic) * 100,
        ];
    }

    protected static function intrinsic18kGram(
        int|float|null $ounceUsd,
        int|float|null $dollarToman
    ): ?float {
        if ($ounceUsd === null || $dollarToman === null) {
            return null;
        }

        $ounceUsd = (float) $ounceUsd;
        $dollarToman = (float) $dollarToman;
        if ($ounceUsd <= 0 || $dollarToman <= 0) {
            return null;
        }

        return ($ounceUsd * $dollarToman / self::TROY_OUNCE_GRAMS) * self::GOLD_18K_PURITY;
    }

    protected function findPriceTable(\PDO $pdo): ?string
    {
        $configured = trim((string) config('prices.gold_price_table', ''));
        if ($configured !== '') {
            return $this->hasRequiredColumns($pdo, $configured) ? $configured : null;
        }

        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%'"
        )->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            if (is_string($table) && $this->hasRequiredColumns($pdo, $table)) {
                return $table;
            }
        }

        return null;
    }

    protected function hasRequiredColumns(\PDO $pdo, string $table): bool
    {
        return count($this->resolveColumnMap($this->tableColumns($pdo, $table))) === count(self::REQUIRED_COLUMNS);
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, string> نگاشت نام استاندارد به نام واقعی ستون
     */
    protected function resolveColumnMap(array $columns): array
    {
        $actualByLowercase = [];
        foreach ($columns as $column) {
            $actualByLowercase[strtolower($column)] = $column;
        }

        $resolved = [];
        foreach (self::COLUMN_ALIASES as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (isset($actualByLowercase[strtolower($alias)])) {
                    $resolved[$canonical] = $actualByLowercase[strtolower($alias)];
                    break;
                }
            }
        }

        return $resolved;
    }

    /** @return array<string, list<string>> */
    protected function databaseSchema(\PDO $pdo): array
    {
        $sources = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $schema = [];

        foreach ($sources as $source) {
            if (is_string($source)) {
                $schema[$source] = $this->tableColumns($pdo, $source);
            }
        }

        return $schema;
    }

    /** @return list<string> */
    protected function tableColumns(\PDO $pdo, string $table): array
    {
        $rows = $pdo->query('PRAGMA table_info('.$this->quoteIdentifier($table).')')->fetchAll();

        return array_values(array_filter(array_column($rows, 'name'), 'is_string'));
    }

    protected function latestOrder(array $columns): string
    {
        foreach (['id', 'updated_at', 'timestamp', 'created_at'] as $column) {
            if (in_array($column, $columns, true)) {
                return $this->quoteIdentifier($column);
            }
        }

        return 'rowid';
    }

    /** @param list<string> $columns */
    protected function actualColumn(array $columns, string $expected): ?string
    {
        foreach ($columns as $column) {
            if (strcasecmp($column, $expected) === 0) {
                return $column;
            }
        }

        return null;
    }

    protected function databasePath(string $path): string
    {
        $path = trim($path);
        if (str_starts_with($path, '~/')) {
            $home = rtrim((string) getenv('HOME'), '/\\');

            return $home === '' ? $path : $home.substr($path, 1);
        }

        return $path;
    }

    protected function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    protected function numericValue(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $normalized = str_replace($ar, $en, str_replace($fa, $en, trim($value)));
        $normalized = str_replace([',', '،', '/', ' ', "\u{00a0}"], '', $normalized);

        if (! is_numeric($normalized)) {
            return null;
        }

        return str_contains($normalized, '.') ? (float) $normalized : (int) $normalized;
    }
}
