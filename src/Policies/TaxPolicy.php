<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Policies;

use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use AlfaSix\AlfakitInvoices\Models\Tax;
use Illuminate\Database\Eloquent\Model;

/**
 * Tax rates are managed by the users `InvoicesPlugin::authorize()` allows. Lines keep a
 * snapshot of their rates, so editing or deleting a tax never changes issued documents.
 */
class TaxPolicy
{
    public function viewAny(Model $user): bool
    {
        return InvoicesPlugin::get()->canManage($user);
    }

    public function view(Model $user, Tax $tax): bool
    {
        return InvoicesPlugin::get()->canManage($user);
    }

    public function create(Model $user): bool
    {
        return InvoicesPlugin::get()->canManage($user);
    }

    public function update(Model $user, Tax $tax): bool
    {
        return InvoicesPlugin::get()->canManage($user);
    }

    public function delete(Model $user, Tax $tax): bool
    {
        return InvoicesPlugin::get()->canManage($user);
    }

    public function deleteAny(Model $user): bool
    {
        return InvoicesPlugin::get()->canManage($user);
    }
}
