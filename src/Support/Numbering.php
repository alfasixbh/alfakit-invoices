<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Support;

use AlfaSix\AlfakitInvoices\AlfakitInvoices;
use AlfaSix\AlfakitInvoices\Enums\InvoiceType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Document numbers from per-type, per-tenant sequences: `INV-2026-00042`.
 *
 * The increment is a single `UPDATE … SET value = value + 1` inside a transaction, so
 * two requests can never get the same number: the database serialises the update (a
 * row lock on MySQL and PostgreSQL, the write lock on SQLite) and each transaction reads
 * back its own value.
 */
final class Numbering
{
    public const DEFAULT_FORMAT = '{YYYY}-{SEQ:5}';

    /**
     * The next number of a type for a tenant (null: no tenant), dated `$date` (today).
     */
    public static function next(InvoiceType $type, ?Model $tenant = null, ?CarbonInterface $date = null, ?string $prefix = null, ?string $format = null): string
    {
        $date ??= Carbon::now();
        $prefix ??= self::prefix($type, $tenant);
        $format ??= self::format($type, $tenant);

        $value = self::increment($type, AlfakitInvoices::tenantScope($tenant), self::period($format, $date));

        return $prefix.self::render($format, $date, $value);
    }

    /**
     * Fill a format: `{YYYY}`, `{YY}`, `{MM}`, `{DD}`, `{SEQ}` and `{SEQ:n}` (zero-padded).
     */
    public static function render(string $format, CarbonInterface $date, int $value): string
    {
        $rendered = preg_replace_callback('/\{SEQ(?::(\d{1,2}))?\}/', static fn (array $matches): string => str_pad((string) $value, (int) ($matches[1] ?? 0), '0', STR_PAD_LEFT), $format) ?? $format;

        return strtr($rendered, [
            '{YYYY}' => $date->format('Y'),
            '{YY}' => $date->format('y'),
            '{MM}' => $date->format('m'),
            '{DD}' => $date->format('d'),
        ]);
    }

    /**
     * The period a format restarts on: daily with `{DD}`, monthly with `{MM}`, yearly with
     * `{YYYY}` or `{YY}`, never without date tokens.
     */
    public static function period(string $format, CarbonInterface $date): string
    {
        return match (true) {
            str_contains($format, '{DD}') => $date->format('Y-m-d'),
            str_contains($format, '{MM}') => $date->format('Y-m'),
            str_contains($format, '{YYYY}'), str_contains($format, '{YY}') => $date->format('Y'),
            default => '',
        };
    }

    public static function validateFormat(string $format): void
    {
        if (preg_match('/\{SEQ(?::\d{1,2})?\}/', $format) !== 1) {
            throw new InvalidArgumentException("The number format [{$format}] needs a {SEQ} or {SEQ:n} token.");
        }
    }

    public static function prefix(InvoiceType $type, ?Model $tenant = null): string
    {
        return AlfakitInvoices::plugin()->getNumberPrefix($type, $tenant);
    }

    public static function format(InvoiceType $type, ?Model $tenant = null): string
    {
        return AlfakitInvoices::plugin()->getNumberFormat($type, $tenant);
    }

    /**
     * The last value handed out (0 when none), e.g. to show "next: INV-2026-00043".
     */
    public static function current(InvoiceType $type, ?Model $tenant = null, ?CarbonInterface $date = null): int
    {
        $model = AlfakitInvoices::sequenceModel();
        $period = self::period(self::format($type, $tenant), $date ?? Carbon::now());

        $value = $model::query()
            ->where('scope', AlfakitInvoices::tenantScope($tenant))
            ->where('type', $type->value)
            ->where('period', $period)
            ->value('value');

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function increment(InvoiceType $type, string $scope, string $period): int
    {
        $model = AlfakitInvoices::sequenceModel();
        $instance = new $model;
        $connection = DB::connection($instance->getConnectionName());
        $table = $instance->getTable();
        $key = ['scope' => $scope, 'type' => $type->value, 'period' => $period];

        return (int) $connection->transaction(static function () use ($connection, $table, $key): int {
            $now = Carbon::now();

            // The update takes the lock; a missing row is created first (a concurrent
            // insert of the same row is ignored thanks to the unique index).
            if ($connection->table($table)->where($key)->increment('value', 1, ['updated_at' => $now]) === 0) {
                $connection->table($table)->insertOrIgnore([...$key, 'value' => 0, 'created_at' => $now, 'updated_at' => $now]);
                $connection->table($table)->where($key)->increment('value', 1, ['updated_at' => $now]);
            }

            $value = $connection->table($table)->where($key)->value('value');

            return is_numeric($value) ? (int) $value : 0;
        }, 5);
    }
}
