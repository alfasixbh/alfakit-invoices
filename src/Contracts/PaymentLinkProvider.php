<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Contracts;

use AlfaSix\AlfakitInvoices\Models\Invoice;

/**
 * Where the "Pay now" button of the customer invoice page goes. Return null to hide it.
 * Once your gateway confirms the payment (in its webhook), record it with
 * `$invoice->recordPayment($amount, method: 'online', reference: $chargeId)`.
 */
interface PaymentLinkProvider
{
    public function paymentUrl(Invoice $invoice): ?string;
}
