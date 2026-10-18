<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Contracts;

/**
 * A model invoices can be billed to (a customer, team or user). Its details are copied
 * onto the document when it is picked, so later changes don't alter issued invoices.
 * Models without this contract work too: their `name`, `email`, `phone`, `address` and
 * `tax_id` attributes are used.
 */
interface InvoiceRecipient
{
    /**
     * @return array{name?: string|null, email?: string|null, phone?: string|null, address?: string|null, tax_id?: string|null}
     */
    public function getInvoiceRecipientDetails(): array;
}
