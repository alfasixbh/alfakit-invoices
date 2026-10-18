<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Support;

use AlfaSix\Alfakit\Support\Url;
use AlfaSix\AlfakitInvoices\AlfakitInvoices;
use AlfaSix\AlfakitInvoices\Contracts\PaymentLinkProvider;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use Throwable;

/**
 * The URL of the customer page's "Pay now" button, for documents with a balance:
 * `InvoicesPlugin::payNowUsing()` first, then the bound `PaymentLinkProvider` (by default
 * `BillingPaymentLink`, which asks the AlfaKit Billing provider when it supports one-off
 * invoice payments). No payment is ever taken here.
 */
final class PayNow
{
    public static function url(Invoice $invoice): ?string
    {
        if (! $invoice->isPayable()) {
            return null;
        }

        try {
            $callback = AlfakitInvoices::plugin($invoice)->getPayNowUsing();
            $url = $callback !== null ? $callback($invoice) : app(PaymentLinkProvider::class)->paymentUrl($invoice);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return is_string($url) ? Url::sanitize($url) : null;
    }
}
