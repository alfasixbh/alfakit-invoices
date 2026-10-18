<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Policies;

use AlfaSix\AlfakitInvoices\Enums\InvoiceStatus;
use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use Illuminate\Database\Eloquent\Model;

/**
 * The default invoice policy: users allowed by `InvoicesPlugin::authorize()` (everyone in
 * the panel by default) may work with invoices, within what each document's state allows
 * (only drafts are deleted; paid and void documents are locked). Replace it with your own
 * `Gate::policy(Invoice::class, …)`; the panel actions check these abilities: `send`,
 * `recordPayment`, `void`, `duplicate`, `convert`, `creditNote`.
 */
class InvoicePolicy
{
    public function viewAny(Model $user): bool
    {
        return $this->allowed($user);
    }

    public function view(Model $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice);
    }

    public function create(Model $user): bool
    {
        return $this->allowed($user);
    }

    public function update(Model $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice) && $invoice->isEditable();
    }

    public function delete(Model $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice) && $invoice->status === InvoiceStatus::Draft && (int) $invoice->amount_paid === 0;
    }

    public function deleteAny(Model $user): bool
    {
        return $this->allowed($user);
    }

    public function send(Model $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice) && $invoice->canBeSent();
    }

    public function recordPayment(Model $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice) && $invoice->isPayable();
    }

    public function void(Model $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice) && $invoice->canBeVoided();
    }

    public function duplicate(Model $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice);
    }

    public function convert(Model $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice) && $invoice->canBeConverted();
    }

    public function creditNote(Model $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice) && $invoice->canBeCredited();
    }

    protected function allowed(Model $user, ?Invoice $invoice = null): bool
    {
        return InvoicesPlugin::get($invoice)->canManage($user);
    }
}
