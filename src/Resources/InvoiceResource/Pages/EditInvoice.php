<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Resources\InvoiceResource\Pages;

use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\ViewAction;
use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\AlfakitInvoices\Actions\InvoiceActions;
use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use AlfaSix\AlfakitInvoices\Resources\Concerns\SavesInvoiceForms;
use AlfaSix\AlfakitInvoices\Resources\InvoiceResource;

class EditInvoice extends EditRecord
{
    use SavesInvoiceForms;

    protected static string $resource = InvoiceResource::class;

    public static function getResource(): string
    {
        return InvoicesPlugin::get()->getInvoiceResource();
    }

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof Invoice ? (string) __('alfakit-invoices::invoices.pages.edit', ['title' => $record->getTitle()]) : parent::getTitle();
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            InvoiceActions::preview(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['type']);

        return $this->resolveCustomer($data);
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if ($record instanceof Invoice) {
            $record->recalculate();
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
