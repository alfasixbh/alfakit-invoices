<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Events;

use AlfaSix\AlfakitInvoices\Models\Invoice;
use AlfaSix\AlfakitInvoices\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentRecorded
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice, public readonly Payment $payment) {}
}
