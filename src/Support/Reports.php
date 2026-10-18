<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Support;

use AlfaSix\Alfakit\Support\Currency;
use AlfaSix\AlfakitInvoices\AlfakitInvoices;
use AlfaSix\AlfakitInvoices\Enums\InvoiceStatus;
use AlfaSix\AlfakitInvoices\Enums\InvoiceType;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Receivables figures for the widgets, per currency (amounts in different currencies are
 * never added together). Each method takes the invoice query to report on, already
 * scoped to the tenant (the resource's `getEloquentQuery()`).
 */
final class Reports
{
    public const AGING_BUCKETS = ['current', '1_30', '31_60', '61_90', '90_plus'];

    /**
     * Unpaid balance of open invoices: `['BHD' => ['amount' => 120500, 'count' => 3]]`.
     *
     * @param  Builder<Invoice>  $query
     * @return array<string, array{amount: int, count: int}>
     */
    public static function outstanding(Builder $query): array
    {
        return self::sumByCurrency((clone $query)
            ->whereIn($query->qualifyColumn('type'), [InvoiceType::Invoice->value, InvoiceType::Receipt->value])
            ->whereIn($query->qualifyColumn('status'), self::values(InvoiceStatus::open())), 'amount_due');
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return array<string, array{amount: int, count: int}>
     */
    public static function overdue(Builder $query): array
    {
        return self::sumByCurrency((clone $query)
            ->where($query->qualifyColumn('type'), InvoiceType::Invoice->value)
            ->where($query->qualifyColumn('status'), InvoiceStatus::Overdue->value), 'amount_due');
    }

    /**
     * Payments received in the month of `$date` (this month), credit notes excluded.
     *
     * @param  Builder<Invoice>  $query
     * @return array<string, array{amount: int, count: int}>
     */
    public static function paidInMonth(Builder $query, ?CarbonInterface $date = null): array
    {
        $date ??= Carbon::now();
        $payments = AlfakitInvoices::paymentModel();
        $ids = (clone $query)->select($query->qualifyColumn('id'));

        $rows = $payments::query()
            ->whereIn('invoice_id', $ids)
            ->where('method', '!=', \AlfaSix\AlfakitInvoices\Models\Payment::METHOD_CREDIT_NOTE)
            ->whereBetween('paid_at', [$date->copy()->startOfMonth()->toDateString(), $date->copy()->endOfMonth()->toDateString()])
            ->selectRaw('currency, SUM(amount) as aggregate, COUNT(*) as records')
            ->groupBy('currency')
            ->get();

        return self::rows($rows->all());
    }

    /**
     * Unpaid balances by days past due: `['BHD' => ['current' => 1000, '1_30' => 0, …]]`.
     *
     * @param  Builder<Invoice>  $query
     * @return array<string, array<string, int>>
     */
    public static function aging(Builder $query, ?CarbonInterface $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $aging = [];

        (clone $query)
            ->where($query->qualifyColumn('type'), InvoiceType::Invoice->value)
            ->whereIn($query->qualifyColumn('status'), self::values(InvoiceStatus::open()))
            ->where($query->qualifyColumn('amount_due'), '>', 0)
            ->select(['id', 'currency', 'due_date', 'amount_due'])
            ->orderBy('id')
            ->chunk(500, static function ($invoices) use (&$aging, $today): void {
                foreach ($invoices as $invoice) {
                    $days = $invoice->due_date === null ? 0 : (int) $invoice->due_date->startOfDay()->diffInDays($today, false);
                    $bucket = match (true) {
                        $days <= 0 => 'current',
                        $days <= 30 => '1_30',
                        $days <= 60 => '31_60',
                        $days <= 90 => '61_90',
                        default => '90_plus',
                    };

                    $aging[$invoice->currency] ??= array_fill_keys(self::AGING_BUCKETS, 0);
                    $aging[$invoice->currency][$bucket] += (int) $invoice->amount_due;
                }
            });

        ksort($aging);

        return $aging;
    }

    /**
     * `BHD 1.200 · USD 300.00`, in the current locale (`—` when empty).
     *
     * @param  array<string, array{amount: int, count: int}>  $totals
     */
    public static function format(array $totals): string
    {
        $parts = [];

        foreach ($totals as $currency => $total) {
            $parts[] = Currency::format($total['amount'], $currency, app()->getLocale(), inMinorUnits: true);
        }

        return $parts === [] ? Currency::format(0, alfakit()->getCurrency(), app()->getLocale(), inMinorUnits: true) : implode(' · ', $parts);
    }

    /**
     * @param  array<string, array{amount: int, count: int}>  $totals
     */
    public static function count(array $totals): int
    {
        return array_sum(array_column($totals, 'count'));
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return array<string, array{amount: int, count: int}>
     */
    private static function sumByCurrency(Builder $query, string $column): array
    {
        $rows = $query->toBase()
            ->selectRaw('currency, SUM('.$column.') as aggregate, COUNT(*) as records')
            ->groupBy('currency')
            ->get();

        return self::rows($rows->all());
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<string, array{amount: int, count: int}>
     */
    private static function rows(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $currency = data_get($row, 'currency');
            $amount = data_get($row, 'aggregate');
            $count = data_get($row, 'records');

            if (is_string($currency) && $currency !== '') {
                $totals[$currency] = ['amount' => is_numeric($amount) ? (int) $amount : 0, 'count' => is_numeric($count) ? (int) $count : 0];
            }
        }

        ksort($totals);

        return $totals;
    }

    /**
     * @param  array<int, InvoiceStatus>  $statuses
     * @return array<int, string>
     */
    private static function values(array $statuses): array
    {
        return array_map(static fn (InvoiceStatus $status): string => $status->value, $statuses);
    }
}
