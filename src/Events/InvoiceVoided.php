<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Events;

use AlfaSix\AlfakitInvoices\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

class InvoiceVoided
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice) {}
}
