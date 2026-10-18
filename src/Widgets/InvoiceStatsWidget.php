<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Widgets;

use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;
use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use AlfaSix\AlfakitInvoices\Support\Reports;
use Illuminate\Database\Eloquent\Builder;

/**
 * Outstanding, overdue and paid this month, per currency, for the current tenant.
 */
class InvoiceStatsWidget extends StatsOverviewWidget
{
    /** @var int|array<string, int|null>|null */
    protected int|array|null $columns = ['default' => 1, 'sm' => 3];

    public static function canView(): bool
    {
        $resource = InvoicesPlugin::get()->getInvoiceResource();

        return $resource::canViewAny();
    }

    protected function getStats(): array
    {
        /** @var Builder<Invoice> $query */
        $query = InvoicesPlugin::get()->getInvoiceResource()::getEloquentQuery();
        $outstanding = Reports::outstanding($query);
        $overdue = Reports::overdue($query);
        $paid = Reports::paidInMonth($query);

        return [
            Stat::make(__('alfakit-invoices::invoices.widgets.outstanding'), Reports::format($outstanding))
                ->icon('hourglass')
                ->description(trans_choice('alfakit-invoices::invoices.widgets.invoices_count', Reports::count($outstanding), ['count' => Reports::count($outstanding)]))
                ->color('primary'),
            Stat::make(__('alfakit-invoices::invoices.widgets.overdue'), Reports::format($overdue))
                ->icon('alarm-clock')
                ->description(trans_choice('alfakit-invoices::invoices.widgets.invoices_count', Reports::count($overdue), ['count' => Reports::count($overdue)]))
                ->color(Reports::count($overdue) > 0 ? 'danger' : 'success'),
            Stat::make(__('alfakit-invoices::invoices.widgets.paid_this_month'), Reports::format($paid))
                ->icon('circle-check')
                ->description(trans_choice('alfakit-invoices::invoices.widgets.payments_count', Reports::count($paid), ['count' => Reports::count($paid)]))
                ->color('success'),
        ];
    }
}
