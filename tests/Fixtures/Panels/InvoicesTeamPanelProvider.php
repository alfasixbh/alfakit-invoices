<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use AlfaSix\AlfakitInvoices\Tests\Fixtures\Models\Customer;
use Illuminate\Database\Eloquent\Model;

class InvoicesTeamPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('invoices-team')
            ->path('billing-team')
            ->login()
            ->middleware(['web'])
            ->currency('USD')
            ->tenant(Team::class, slugAttribute: 'slug')
            ->plugin(
                InvoicesPlugin::make()
                    ->customers(static fn (?Model $tenant) => Customer::query()->where('team_id', $tenant?->getKey()), titleAttribute: 'company'),
            );
    }
}
