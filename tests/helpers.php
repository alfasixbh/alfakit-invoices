<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Tests;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use AlfaSix\AlfakitInvoices\Models\Tax;
use AlfaSix\AlfakitInvoices\Tests\Fixtures\Models\InvoicesUser;
use Illuminate\Database\Eloquent\Model;

function user(string $name = 'Ada Admin', ?string $email = null): InvoicesUser
{
    return InvoicesUser::query()->create(['name' => $name, 'email' => $email ?? uniqid('user', true).'@example.com', 'password' => 'password']);
}

function team(string $name): Team
{
    return Team::query()->create(['name' => $name, 'slug' => strtolower($name)]);
}

function tax(string $name = 'VAT', string $rate = '10', bool $inclusive = false, bool $compound = false, ?Model $tenant = null, bool $default = false): Tax
{
    $tax = new Tax(['name' => $name, 'rate' => $rate, 'is_inclusive' => $inclusive, 'is_compound' => $compound, 'is_default' => $default]);

    if ($tenant !== null) {
        $tax->tenant()->associate($tenant);
    }

    $tax->save();

    return $tax;
}

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<int, array<string, mixed>>|null  $items
 */
function invoice(array $attributes = [], ?array $items = null, ?Model $tenant = null): Invoice
{
    return Invoice::createWithItems(
        ['customer_name' => 'Globex', 'customer_email' => 'billing@globex.test', 'currency' => 'BHD', ...$attributes],
        $items ?? [['description' => 'Consulting', 'quantity' => '2', 'unit_price' => 50000]],
        $tenant,
    );
}
