<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Commands;

use AlfaSix\AlfakitInvoices\AlfakitInvoices;
use AlfaSix\AlfakitInvoices\Enums\InvoiceStatus;
use AlfaSix\AlfakitInvoices\Enums\InvoiceType;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Marks sent, viewed and partially paid invoices whose due date has passed as overdue
 * (all tenants). Scheduled daily by default (`overdue.schedule`).
 */
class MarkOverdueCommand extends Command
{
    protected $signature = 'alfakit-invoices:mark-overdue {--date= : Treat this date (Y-m-d) as today}';

    protected $description = 'Mark unpaid invoices past their due date as overdue';

    public function handle(): int
    {
        $date = $this->option('date');
        $today = is_string($date) && $date !== '' ? Carbon::parse($date)->startOfDay() : Carbon::today();
        $model = AlfakitInvoices::invoiceModel();
        $count = 0;

        $model::query()
            ->ofType(InvoiceType::Invoice)
            ->whereIn('status', [InvoiceStatus::Sent->value, InvoiceStatus::Viewed->value, InvoiceStatus::PartiallyPaid->value])
            ->where('amount_due', '>', 0)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today->toDateString())
            ->chunkById(200, static function ($invoices) use (&$count, $today): void {
                foreach ($invoices as $invoice) {
                    if ($invoice->markAsOverdueIfPastDue($today)) {
                        $count++;
                    }
                }
            });

        $this->components->info(trans_choice('alfakit-invoices::invoices.commands.overdue', $count, ['count' => $count]));

        return self::SUCCESS;
    }
}
