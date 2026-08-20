<?php

namespace App\Services;

use App\Support\BotLog;

class GoldPriceService
{
    /** ستون‌هایی که وجودشان جدول قیمت طلا را مشخص می‌کند. */
    private const REQUIRED_COLUMNS = [
        'bahar_sell', 'bahar_buy',
        'nim_sell', 'nim_buy',
        'rob_sel', 'rob_buy',
        'mithqal_sell', 'mithqal_buy',
        'geram_sell', 'geram_buy',
        'ounce',
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
                ]);

                return null;
            }

            $columns = $this->tableColumns($pdo, $table);
            $order = $this->latestOrder($columns);
            $quotedColumns = implode(', ', array_map($this->quoteIdentifier(...), self::REQUIRED_COLUMNS));
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

    protected function findPriceTable(\PDO $pdo): ?string
    {
        $configured = trim((string) config('prices.gold_price_table', ''));
        if ($configured !== '') {
            return $this->hasRequiredColumns($pdo, $configured) ? $configured : null;
        }

        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
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
        $columns = $this->tableColumns($pdo, $table);

        return array_diff(self::REQUIRED_COLUMNS, $columns) === [];
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
