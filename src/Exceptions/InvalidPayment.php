<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Exceptions;

use DomainException;

/**
 * A payment that can't be recorded (not positive, more than the balance, a document
 * that doesn't take payments). The message is translated and safe to show.
 */
class InvalidPayment extends DomainException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        return new self((string) __('alfakit-invoices::invoices.errors.'.$key, $replace));
    }
}
