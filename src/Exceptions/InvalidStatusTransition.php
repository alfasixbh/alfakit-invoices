<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Exceptions;

use AlfaSix\AlfakitInvoices\Enums\InvoiceStatus;
use AlfaSix\AlfakitInvoices\Enums\InvoiceType;
use DomainException;

/**
 * A document can't move to that status (or do that) from where it is. The message is
 * translated and safe to show to panel users.
 */
class InvalidStatusTransition extends DomainException
{
    public static function make(InvoiceStatus $from, InvoiceStatus $to): self
    {
        return new self((string) __('alfakit-invoices::invoices.errors.transition', ['from' => $from->getLabel(), 'to' => $to->getLabel()]));
    }

    public static function forType(InvoiceType $type, InvoiceStatus $to): self
    {
        return new self((string) __('alfakit-invoices::invoices.errors.type_status', ['type' => $type->getLabel(), 'status' => $to->getLabel()]));
    }

    public static function because(string $key): self
    {
        return new self((string) __('alfakit-invoices::invoices.errors.'.$key));
    }
}
