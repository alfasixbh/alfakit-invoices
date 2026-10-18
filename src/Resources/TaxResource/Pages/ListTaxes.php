<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Resources\TaxResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use AlfaSix\AlfakitInvoices\Resources\TaxResource;

class ListTaxes extends ListRecords
{
    protected static string $resource = TaxResource::class;

    public static function getResource(): string
    {
        return InvoicesPlugin::get()->getTaxResource();
    }
}
