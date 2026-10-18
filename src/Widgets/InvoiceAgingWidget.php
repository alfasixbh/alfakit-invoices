<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Widgets;

use AlfaSix\Alfakit\Support\Currency;
use AlfaSix\Alfakit\Widgets\ChartWidget;
use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use AlfaSix\AlfakitInvoices\Support\Reports;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Unpaid invoice balances by days past due (current, 1–30, 31–60, 61–90, 90+), one
 * dataset per currency, with a table as the accessible alternative.
 */
class InvoiceAgingWidget extends ChartWidget
{
    protected ?string $maxHeight = '280px';

    /** @var array<string, array<string, int>>|null */
    protected ?array $aging = null;

    public static function canView(): bool
    {
        $resource = InvoicesPlugin::get()->getInvoiceResource();

        return $resource::canViewAny();
    }

    protected function getHeading(): ?string
    {
        return (string) __('alfakit-invoices::invoices.widgets.aging');
    }

    protected function getDescription(): ?string
    {
        return (string) __('alfakit-invoices::invoices.widgets.aging_description');
    }

    protected function getChartType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $aging = $this->aging();

        if ($aging === []) {
            return [];
        }

        $datasets = [];

        foreach ($aging as $currency => $buckets) {
            $datasets[] = [
                'label' => $currency,
                // Chart values only: amounts stay integers everywhere else.
                'data' => array_map(static fn (int $amount): float => (float) Currency::fromMinorUnits($amount, $currency), array_values($buckets)),
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => array_map(static fn (string $bucket): string => (string) __('alfakit-invoices::invoices.widgets.buckets.'.$bucket), Reports::AGING_BUCKETS),
        ];
    }

    protected function getChartAssistiveContent(): string|Htmlable|null
    {
        $aging = $this->aging();

        if ($aging === []) {
            return null;
        }

        $locale = app()->getLocale();
        $head = '<tr><th scope="col">'.e(__('alfakit-invoices::invoices.fields.currency')).'</th>';

        foreach (Reports::AGING_BUCKETS as $bucket) {
            $head .= '<th scope="col">'.e(__('alfakit-invoices::invoices.widgets.buckets.'.$bucket)).'</th>';
        }

        $rows = '';

        foreach ($aging as $currency => $buckets) {
            $rows .= '<tr><th scope="row">'.e($currency).'</th>';

            foreach ($buckets as $amount) {
                $rows .= '<td>'.e(Currency::format($amount, $currency, $locale, inMinorUnits: true)).'</td>';
            }

            $rows .= '</tr>';
        }

        return new HtmlString('<table><thead>'.$head.'</tr></thead><tbody>'.$rows.'</tbody></table>');
    }

    /**
     * @return array<string, array<string, int>>
     */
    protected function aging(): array
    {
        /** @var Builder<Invoice> $query */
        $query = InvoicesPlugin::get()->getInvoiceResource()::getEloquentQuery();

        return $this->aging ??= Reports::aging($query);
    }
}
