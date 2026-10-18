<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Support;

use AlfaSix\AlfakitInvoices\AlfakitInvoices;
use AlfaSix\AlfakitInvoices\Contracts\PaymentLinkProvider;
use AlfaSix\AlfakitInvoices\Models\Invoice;

/**
 * The default `PaymentLinkProvider`: when `alfasix/alfakit-billing` is installed and the
 * invoice's panel has a billing provider with an `invoicePaymentUrl(Invoice $invoice)`
 * method (your own provider subclass), its URL is used. Otherwise there is no button.
 */
class BillingPaymentLink implements PaymentLinkProvider
{
    public const BILLING_PLUGIN = 'AlfaSix\AlfakitBilling\BillingPlugin';

    public function paymentUrl(Invoice $invoice): ?string
    {
        $class = self::BILLING_PLUGIN;
        $panel = AlfakitInvoices::panel($invoice);

        if (! class_exists($class) || $panel === null || ! $panel->hasPlugin('billing')) {
            return null;
        }

        $provider = $panel->getPlugin('billing');
        $provider = method_exists($provider, 'getProvider') ? $provider->getProvider() : null;

        if (! is_object($provider) || ! method_exists($provider, 'invoicePaymentUrl')) {
            return null;
        }

        $url = $provider->invoicePaymentUrl($invoice);

        return is_string($url) && $url !== '' ? $url : null;
    }
}
