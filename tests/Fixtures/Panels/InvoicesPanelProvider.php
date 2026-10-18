<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use AlfaSix\AlfakitInvoices\Tests\Fixtures\Models\Customer;
use Illuminate\Database\Eloquent\Model;

class InvoicesPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('invoices')
            ->path('billing')
            ->login()
            ->middleware(['web'])
            ->brandName('Acme Trading')
            ->currency('BHD')
            ->plugin(
                InvoicesPlugin::make()
                    ->authorize(static fn (Model $user): bool => $user->getAttribute('email') !== 'denied@example.com')
                    ->customers(static fn () => Customer::query()->whereNull('team_id'), titleAttribute: 'company')
                    ->seller(['name' => 'Acme Trading W.L.L.', 'address' => "Building 1\nManama", 'tax_id' => 'VAT-123'])
                    ->dashboardWidgets(),
            );
    }
}
