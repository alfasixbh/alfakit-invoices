<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Resources\InvoiceResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\AlfakitInvoices\Enums\InvoiceType;
use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use AlfaSix\AlfakitInvoices\Models\Payment;
use AlfaSix\AlfakitInvoices\Resources\Concerns\SavesInvoiceForms;
use AlfaSix\AlfakitInvoices\Resources\InvoiceResource;

class CreateInvoice extends CreateRecord
{
    use SavesInvoiceForms;

    protected static string $resource = InvoiceResource::class;

    public static function getResource(): string
    {
        return InvoicesPlugin::get()->getInvoiceResource();
    }

    public function getTitle(): string
    {
        return (string) __('alfakit-invoices::invoices.pages.create', ['type' => InvoiceResource::typeFromRequest()->getLabel()]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->resolveCustomer($data);
    }

    protected function afterCreate(): void
    {
        $record = $this->getCreatedRecord();

        if (! $record instanceof Invoice) {
            return;
        }

        $record->recalculate();

        // A receipt records a sale that was paid on the spot.
        if ($record->type === InvoiceType::Receipt && (int) $record->amount_due > 0) {
            $user = alfakit()->auth();
            $record->markAsPaid($record->issue_date, (string) (array_key_first(Payment::methodOptions()) ?? 'other'), recordedBy: $user instanceof \Illuminate\Database\Eloquent\Model ? $user : null);
        }
    }

    protected function getRedirectUrl(): string
    {
        $record = $this->getCreatedRecord();
        $resource = static::getResource();

        return $record !== null ? $resource::getUrl('view', ['record' => $record]) : $resource::getUrl('index');
    }
}
