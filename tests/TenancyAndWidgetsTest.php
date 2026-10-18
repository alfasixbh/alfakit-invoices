<?php

declare(strict_types=1);

use AlfaSix\AlfakitInvoices\Models\Invoice;
use AlfaSix\AlfakitInvoices\Models\Tax;
use AlfaSix\AlfakitInvoices\Resources\InvoiceResource\Pages\CreateInvoice;
use AlfaSix\AlfakitInvoices\Resources\InvoiceResource\Pages\ListInvoices;
use AlfaSix\AlfakitInvoices\Resources\InvoiceResource\Pages\ViewInvoice;
use AlfaSix\AlfakitInvoices\Support\Reports;
use AlfaSix\AlfakitInvoices\Tests\Fixtures\Models\Customer;
use AlfaSix\AlfakitInvoices\Tests\TestCase;
use Illuminate\Support\Carbon;

use function AlfaSix\AlfakitInvoices\Tests\invoice;
use function AlfaSix\AlfakitInvoices\Tests\tax;
use function AlfaSix\AlfakitInvoices\Tests\team;
use function AlfaSix\AlfakitInvoices\Tests\user;
use function Pest\Laravel\actingAs;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    Carbon::setTestNow('2026-04-15 09:00:00');
});

it('shows each tenant only its own documents', function (): void {
    $alpha = team('Alpha');
    $beta = team('Beta');
    $user = user();
    $user->teams()->attach([$alpha->id, $beta->id]);
    actingAs($user);

    $mine = invoice(tenant: $alpha);
    $theirs = invoice(tenant: $beta);

    alfakit()->testPage(ListInvoices::class, ['tenant' => $alpha], panel: 'invoices-team')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    alfakit()->testPage(ViewInvoice::class, ['tenant' => $alpha, 'record' => $theirs], panel: 'invoices-team')->assertNotFound();
});

it('creates documents for the current tenant with its own numbers, taxes and customers', function (): void {
    $alpha = team('Alpha');
    $beta = team('Beta');
    $user = user();
    $user->teams()->attach([$alpha->id, $beta->id]);
    actingAs($user);

    invoice(tenant: $beta);
    invoice(tenant: $beta);
    $alphaVat = tax('VAT', '10', tenant: $alpha);
    $betaVat = tax('VAT', '20', tenant: $beta);
    $betaCustomer = Customer::query()->create(['company' => 'Beta Co', 'team_id' => $beta->id]);

    $line = ['description' => 'Work', 'quantity' => '1', 'unit_price' => '100', 'discount_type' => 'percent', 'tax_ids' => [(string) $alphaVat->id]];

    // Another tenant's customer is refused.
    alfakit()->testPage(CreateInvoice::class, ['tenant' => $alpha], panel: 'invoices-team')
        ->fillForm(['customer_name' => 'X', 'billable_id' => (string) $betaCustomer->id, 'items' => [$line]])
        ->call('create')
        ->assertHasFormErrors(['billable_id']);

    // Another tenant's tax isn't offered.
    alfakit()->testPage(CreateInvoice::class, ['tenant' => $alpha], panel: 'invoices-team')
        ->fillForm(['customer_name' => 'X', 'items' => [[...$line, 'tax_ids' => [(string) $betaVat->id]]]])
        ->call('create')
        ->assertHasFormErrors();

    alfakit()->testPage(CreateInvoice::class, ['tenant' => $alpha], panel: 'invoices-team')
        ->assertFormSet(['currency' => 'USD'])
        ->fillForm(['customer_name' => 'Alpha customer', 'items' => [$line]])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::query()->forTenant($alpha)->sole();

    expect($invoice->number)->toBe('INV-2026-00001')
        ->and($invoice->tax_total)->toBe(1000)
        ->and($invoice->getTenant()?->is($alpha))->toBeTrue()
        ->and(array_keys(Tax::options($alpha)))->toBe([$alphaVat->id]);
});

it('ignores another tenant\'s tax ids when recalculating', function (): void {
    $alpha = team('Alpha');
    $beta = team('Beta');
    $betaVat = tax('VAT', '20', tenant: $beta);

    $invoice = invoice([], [['description' => 'X', 'quantity' => '1', 'unit_price' => 1000, 'tax_ids' => [$betaVat->id]]], $alpha);

    expect($invoice->tax_total)->toBe(0)->and($invoice->items[0]->tax_ids)->toBe([]);
});

it('reports outstanding, overdue, paid this month and aging per currency', function (): void {
    $alpha = team('Alpha');

    $open = invoice(['due_date' => '2026-05-01']);
    $open->markAsSent();
    $late = invoice(['due_date' => '2026-03-01', 'currency' => 'USD']);
    $late->markAsSent();
    $late->markAsOverdueIfPastDue();
    $paid = invoice();
    $paid->markAsSent();
    $paid->recordPayment(25000);
    invoice(tenant: $alpha)->markAsSent();

    $query = Invoice::query()->forTenant(null);

    expect(Reports::outstanding($query))->toBe(['BHD' => ['amount' => 175000, 'count' => 2], 'USD' => ['amount' => 100000, 'count' => 1]])
        ->and(Reports::overdue($query))->toBe(['USD' => ['amount' => 100000, 'count' => 1]])
        ->and(Reports::paidInMonth($query))->toBe(['BHD' => ['amount' => 25000, 'count' => 1]])
        ->and(Reports::aging($query)['USD']['31_60'])->toBe(100000)
        ->and(Reports::aging($query)['BHD']['current'])->toBe(175000);
});

it('puts the stats widget on the list page', function (): void {
    actingAs(user());
    $invoice = invoice();
    $invoice->markAsSent();

    $response = alfakit()->testPage(ListInvoices::class, panel: 'invoices')->assertOk();

    expect(json_encode($response->getProps()))->toContain('invoice-stats-widget');
});
