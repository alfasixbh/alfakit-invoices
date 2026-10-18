<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Resources\Concerns;

use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use Illuminate\Validation\ValidationException;

/**
 * Shared by the create and edit pages: the picked customer must be one of the customers
 * the plugin offers (of the current tenant); its morph class is stored with it.
 *
 * @mixin \AlfaSix\Alfakit\Resources\Pages\Page
 */
trait SavesInvoiceForms
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function resolveCustomer(array $data): array
    {
        if (! array_key_exists('billable_id', $data)) {
            return $data;
        }

        $id = $data['billable_id'];

        if (! is_scalar($id) || (string) $id === '') {
            return [...$data, 'billable_type' => null, 'billable_id' => null];
        }

        $customer = InvoicesPlugin::get()->getCustomersQuery(alfakit()->getTenant())?->whereKey($id)->first();

        if ($customer === null) {
            throw ValidationException::withMessages(['billable_id' => __('validation.exists', ['attribute' => __('alfakit-invoices::invoices.fields.customer')])]);
        }

        return [...$data, 'billable_type' => $customer->getMorphClass(), 'billable_id' => $customer->getKey()];
    }
}
