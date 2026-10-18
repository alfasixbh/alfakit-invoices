<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Resources\TaxResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use AlfaSix\AlfakitInvoices\Resources\TaxResource;

class EditTax extends EditRecord
{
    protected static string $resource = TaxResource::class;

    public static function getResource(): string
    {
        return InvoicesPlugin::get()->getTaxResource();
    }
}
