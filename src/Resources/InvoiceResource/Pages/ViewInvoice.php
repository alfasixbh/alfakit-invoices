<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Resources\InvoiceResource\Pages;

use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Resources\Pages\ViewRecord;
use AlfaSix\AlfakitInvoices\Actions\InvoiceActions;
use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use AlfaSix\AlfakitInvoices\Resources\InvoiceResource;

/**
 * The document with its lines, totals, payments and history, and every action.
 */
class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    public static function getResource(): string
    {
        return InvoicesPlugin::get()->getInvoiceResource();
    }

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof Invoice ? $record->getTitle() : parent::getTitle();
    }

    protected function getHeaderActions(): array
    {
        $actions = InvoiceActions::all();

        return [
            $actions['send'],
            $actions['recordPayment'],
            EditAction::make(),
            ActionGroup::make([
                $actions['preview'],
                $actions['download'],
                $actions['copyLink'],
                $actions['markSent'],
                $actions['markPaid'],
                $actions['duplicate'],
                $actions['convert'],
                $actions['creditNote'],
                $actions['void'],
                DeleteAction::make(),
            ])->label(__('alfakit-invoices::invoices.actions.more'))->icon('ellipsis')->color('gray')->button(),
        ];
    }
}
