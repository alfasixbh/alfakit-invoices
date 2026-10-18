<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Events;

use AlfaSix\AlfakitInvoices\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/** A document was sent to the customer (by email or marked as sent). */
class InvoiceSent
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice) {}
}
