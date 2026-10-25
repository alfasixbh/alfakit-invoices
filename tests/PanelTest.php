<?php

declare(strict_types=1);

use AlfaSix\AlfakitInvoices\Enums\InvoiceStatus;
use AlfaSix\AlfakitInvoices\Enums\InvoiceType;
use AlfaSix\AlfakitInvoices\Mail\InvoiceMail;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use AlfaSix\AlfakitInvoices\Models\Payment;
use AlfaSix\AlfakitInvoices\Resources\InvoiceResource\Pages\CreateInvoice;
use AlfaSix\AlfakitInvoices\Resources\InvoiceResource\Pages\EditInvoice;
use AlfaSix\AlfakitInvoices\Resources\InvoiceResource\Pages\ListInvoices;
use AlfaSix\AlfakitInvoices\Resources\InvoiceResource\Pages\ViewInvoice;
use AlfaSix\AlfakitInvoices\Tests\Fixtures\Models\Customer;
use AlfaSix\AlfakitInvoices\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

use function AlfaSix\AlfakitInvoices\Tests\invoice;
use function AlfaSix\AlfakitInvoices\Tests\tax;
use function AlfaSix\AlfakitInvoices\Tests\user;
use function Pest\Laravel\actingAs;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    Carbon::setTestNow('2026-04-01 09:00:00');
    actingAs(user());
});

it('creates an invoice with live totals computed on the server', function (): void {
    $vat = tax('VAT', '10', default: true);

    $page = alfakit()->testPage(CreateInvoice::class, panel: 'invoices')
        ->assertOk()
        ->assertFormSet(['type' => 'invoice', 'currency' => 'BHD', 'issue_date' => '2026-04-01', 'due_date' => '2026-05-01'])
        ->fillForm([
            'customer_name' => 'Globex',
            'customer_email' => 'ap@globex.test',
            'items' => [
                ['description' => 'Design', 'quantity' => '3', 'unit_price' => '12.500', 'discount_type' => 'percent', 'discount_value' => '10', 'tax_ids' => [(string) $vat->id]],
                ['description' => 'Hosting', 'quantity' => '1', 'unit_price' => '5', 'discount_type' => 'fixed', 'discount_value' => '1', 'tax_ids' => []],
            ],
        ]);

    // 37.500 − 3.750 = 33.750 + VAT 3.375; 5.000 − 1.000 = 4.000 → 41.125 BHD.
    $content = static fn (string $expected): Closure => static fn (array $component): bool => str_contains((string) ($component['content'] ?? ''), $expected);

    $page->assertSchemaComponentExists('totals-subtotal', checkComponentUsing: $content('42.500'))
        ->assertSchemaComponentExists('totals-discount', checkComponentUsing: $content('4.750'))
        ->assertSchemaComponentExists('totals-tax-0', checkComponentUsing: $content('3.375'))
        ->assertSchemaComponentExists('totals-total', checkComponentUsing: $content('41.125'));

    $page->call('create')->assertHasNoFormErrors();

    $invoice = Invoice::query()->sole();

    expect($invoice->number)->toBe('INV-2026-00001')
        ->and($invoice->total)->toBe(41125)
        ->and($invoice->tax_total)->toBe(3375)
        ->and($invoice->items)->toHaveCount(2)
        ->and($invoice->items[0]->unit_price)->toBe(12500)
        ->and($invoice->items[0]->taxes[0]['name'] ?? null)->toBe('VAT')
        ->and($invoice->panel_id)->toBe('invoices')
        ->and($invoice->status)->toBe(InvoiceStatus::Draft);
});

it('never trusts totals posted with the form', function (): void {
    $page = alfakit()->testPage(CreateInvoice::class, panel: 'invoices')
        ->fillForm([
            'customer_name' => 'Globex',
            'total' => 1,
            'amount_paid' => 999999,
            'status' => 'paid',
            'items' => [['description' => 'Work', 'quantity' => '2', 'unit_price' => '10', 'discount_type' => 'percent', 'tax_ids' => []]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::query()->sole();

    expect($invoice->total)->toBe(20000)->and($invoice->amount_paid)->toBe(0)->and($invoice->status)->toBe(InvoiceStatus::Draft);
});

it('validates the line items', function (): void {
    alfakit()->testPage(CreateInvoice::class, panel: 'invoices')
        ->fillForm([
            'customer_name' => 'Globex',
            'items' => [['description' => '', 'quantity' => '-1', 'unit_price' => '1.23456', 'discount_type' => 'percent', 'discount_value' => '150', 'tax_ids' => ['999']]],
        ])
        ->call('create')
        ->assertHasFormErrors(['items.0.description', 'items.0.quantity', 'items.0.unit_price', 'items.0.discount_value']);

    expect(Invoice::query()->count())->toBe(0);
});

it('creates quotes and receipts from the type in the URL; receipts are paid', function (): void {
    alfakit()->testPage(CreateInvoice::class, ['type' => 'receipt'], panel: 'invoices')
        ->assertFormSet(['type' => 'receipt'])
        ->fillForm(['customer_name' => 'Walk-in', 'items' => [['description' => 'Perfume', 'quantity' => '1', 'unit_price' => '25', 'discount_type' => 'percent', 'tax_ids' => []]]])
        ->call('create')
        ->assertHasNoFormErrors();

    $receipt = Invoice::query()->sole();

    expect($receipt->type)->toBe(InvoiceType::Receipt)
        ->and($receipt->number)->toBe('RCT-2026-00001')
        ->and($receipt->status)->toBe(InvoiceStatus::Paid)
        ->and($receipt->payments()->sole()->amount)->toBe(25000);
});

it('fills the customer details from the picked customer', function (): void {
    $customer = Customer::query()->create(['company' => 'Initech', 'email' => 'ap@initech.test', 'address' => 'Riffa', 'vat' => 'BH-9']);
    $other = Customer::query()->create(['company' => 'Hidden', 'team_id' => 5]);

    $page = alfakit()->testPage(CreateInvoice::class, panel: 'invoices')
        ->fillForm(['billable_id' => (string) $customer->id])
        ->assertFormSet(['customer_name' => 'Initech', 'customer_email' => 'ap@initech.test', 'customer_tax_id' => 'BH-9'])
        ->fillForm(['items' => [['description' => 'X', 'quantity' => '1', 'unit_price' => '1', 'discount_type' => 'percent', 'tax_ids' => []]]])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::query()->sole();
    expect($invoice->billable?->is($customer))->toBeTrue();

    // A customer outside the offered query is refused.
    alfakit()->testPage(CreateInvoice::class, panel: 'invoices')
        ->fillForm(['customer_name' => 'Hidden', 'billable_id' => (string) $other->id, 'items' => [['description' => 'X', 'quantity' => '1', 'unit_price' => '1', 'discount_type' => 'percent', 'tax_ids' => []]]])
        ->call('create')
        ->assertHasFormErrors(['billable_id']);

    expect(Invoice::query()->count())->toBe(1);
});

it('edits a draft and recalculates', function (): void {
    $invoice = invoice();

    alfakit()->testPage(EditInvoice::class, ['record' => $invoice], panel: 'invoices')
        ->assertOk()
        ->fillForm(['items' => [['description' => 'Consulting', 'quantity' => '4', 'unit_price' => '50', 'discount_type' => 'percent', 'tax_ids' => []]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($invoice->refresh()->total)->toBe(200000)->and($invoice->items()->count())->toBe(1);
});

it('locks paid and void documents', function (): void {
    $paid = invoice();
    $paid->markAsPaid();

    alfakit()->testPage(EditInvoice::class, ['record' => $paid], panel: 'invoices')->assertForbidden();
    alfakit()->testPage(ViewInvoice::class, ['record' => $paid], panel: 'invoices')
        ->assertOk()
        ->assertActionHidden('recordPayment')
        ->assertActionHidden('void')
        ->assertActionVisible('duplicate');
});

it('sends an invoice by queued email with a secure link and the document attached', function (): void {
    Mail::fake();
    $invoice = invoice();

    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice], panel: 'invoices')
        ->callAction('send', ['to' => 'ap@globex.test', 'cc' => ['cfo@globex.test'], 'subject' => null, 'message' => null, 'attach' => true])
        ->assertHasNoActionErrors()
        ->assertNotified('Sending to ap@globex.test');

    Mail::assertQueued(InvoiceMail::class, function (InvoiceMail $mail) use ($invoice): bool {
        $mail->assertHasSubject('Invoice INV-2026-00001 from Acme Trading W.L.L.');
        $mail->assertSeeInHtml('100.000');
        $mail->assertSeeInHtml(e((string) $invoice->refresh()->getCustomerUrl()), false);

        return $mail->hasTo('ap@globex.test') && $mail->hasCc('cfo@globex.test') && $mail->attachDocument;
    });

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Sent)->and($invoice->sent_at)->not->toBeNull();
});

it('validates the recipient when sending', function (): void {
    Mail::fake();
    $invoice = invoice();

    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice], panel: 'invoices')
        ->callAction('send', ['to' => 'not-an-email', 'cc' => ['also bad']])
        ->assertHasActionErrors(['to', 'cc.0']);

    Mail::assertNothingQueued();
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft);
});

it('records payments from the panel and rejects overpayments', function (): void {
    $invoice = invoice();
    $invoice->markAsSent();

    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice], panel: 'invoices')
        ->callAction('recordPayment', ['amount' => '30.500', 'paid_at' => '2026-03-31', 'method' => 'cash', 'reference' => 'R-1'])
        ->assertHasNoActionErrors();

    expect($invoice->refresh()->amount_paid)->toBe(30500)
        ->and($invoice->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and(Payment::query()->sole()->recordedBy)->not->toBeNull();

    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice], panel: 'invoices')
        ->callAction('recordPayment', ['amount' => '70', 'paid_at' => '2026-04-01', 'method' => 'cash'])
        ->assertHasActionErrors(['amount']);

    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice], panel: 'invoices')
        ->callAction('markPaid', ['method' => 'card'])
        ->assertHasNoActionErrors();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)->and($invoice->amount_due)->toBe(0);
});

it('fills the payment form with the balance due, so submitting it pays the invoice', function (): void {
    $invoice = invoice();
    $invoice->markAsSent();
    $due = (int) $invoice->refresh()->amount_due;

    // The amount the form was filled with is what the browser sends back.
    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice], panel: 'invoices')
        ->callAction('recordPayment', ['paid_at' => '2026-03-31', 'method' => 'cash'])
        ->assertHasNoActionErrors();

    expect($invoice->refresh()->amount_paid)->toBe($due)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);
});

it('duplicates, converts quotes and issues credit notes from the panel', function (): void {
    $quote = invoice(['type' => InvoiceType::Quote]);

    alfakit()->testPage(ViewInvoice::class, ['record' => $quote], panel: 'invoices')
        ->assertActionHidden('creditNote')
        ->assertActionHidden('recordPayment')
        ->callAction('convert')
        ->assertHasNoActionErrors();

    $invoice = Invoice::query()->where('type', 'invoice')->sole();
    expect($invoice->source_id)->toBe($quote->id);

    alfakit()->testPage(ViewInvoice::class, ['record' => $quote], panel: 'invoices')->assertActionHidden('convert');

    $invoice->markAsSent();

    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice], panel: 'invoices')
        ->callAction('creditNote', ['apply' => true])
        ->assertHasNoActionErrors();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Invoice::query()->where('type', 'credit_note')->sole()->source_id)->toBe($invoice->id);

    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice], panel: 'invoices')
        ->callAction('duplicate')
        ->assertHasNoActionErrors();

    expect(Invoice::query()->where('type', 'invoice')->count())->toBe(2);
});

it('voids from the panel unless there are payments', function (): void {
    $invoice = invoice();
    $paid = invoice();
    $paid->recordPayment(100);

    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice], panel: 'invoices')
        ->callAction('void')
        ->assertHasNoActionErrors();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Void);

    alfakit()->testPage(ViewInvoice::class, ['record' => $paid], panel: 'invoices')->assertActionHidden('void');
});

it('lists documents by type with stats', function (): void {
    $invoice = invoice();
    $invoice->markAsSent();
    $quote = invoice(['type' => InvoiceType::Quote]);

    alfakit()->testPage(ListInvoices::class, panel: 'invoices')
        ->assertOk()
        ->assertCanSeeTableRecords([$invoice, $quote]);

    alfakit()->testPage(ListInvoices::class, ['activeTab' => 'quote'], panel: 'invoices')
        ->assertCanSeeTableRecords([$quote])
        ->assertCanNotSeeTableRecords([$invoice]);

    alfakit()->testPage(ListInvoices::class, panel: 'invoices')
        ->filterTable('unpaid', true)
        ->assertCanSeeTableRecords([$invoice])
        ->assertCanNotSeeTableRecords([$quote]);
});

it('keeps users the plugin does not authorise out', function (): void {
    actingAs(user('Denied', 'denied@example.com'));
    $invoice = invoice();

    alfakit()->testPage(ListInvoices::class, panel: 'invoices')->assertForbidden();
    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice], panel: 'invoices')->assertForbidden();
    alfakit()->testPage(CreateInvoice::class, panel: 'invoices')->assertForbidden();
});

it('respects a custom policy for the actions', function (): void {
    Illuminate\Support\Facades\Gate::policy(Invoice::class, InvoicesReadOnlyPolicy::class);
    Mail::fake();
    $invoice = invoice();

    alfakit()->testPage(ViewInvoice::class, ['record' => $invoice], panel: 'invoices')
        ->assertOk()
        ->assertActionHidden('send')
        ->assertActionHidden('recordPayment')
        ->assertActionHidden('void');

    $page = alfakit()->testPage(ViewInvoice::class, ['record' => $invoice], panel: 'invoices');

    try {
        $page->callAction('send', ['to' => 'x@example.com']);
    } catch (Throwable) {
        // A hidden action can't be found or run; either way nothing is sent.
    }

    Mail::assertNothingQueued();
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft);
});

final class InvoicesReadOnlyPolicy
{
    public function viewAny(mixed $user): bool
    {
        return true;
    }

    public function view(mixed $user, Invoice $invoice): bool
    {
        return true;
    }

    public function send(mixed $user, Invoice $invoice): bool
    {
        return false;
    }

    public function recordPayment(mixed $user, Invoice $invoice): bool
    {
        return false;
    }

    public function void(mixed $user, Invoice $invoice): bool
    {
        return false;
    }
}

it('gives the line items the whole width of their section', function (): void {
    $form = AlfaSix\AlfakitInvoices\Resources\InvoiceResource::form(AlfaSix\Alfakit\Schemas\Schema::make());
    $items = $form->getComponent(fn (AlfaSix\Alfakit\Schemas\Components\Component $component): bool => $component instanceof AlfaSix\Alfakit\Forms\Components\Repeater);

    expect($items?->getColumnSpan())->toBe(['default' => 'full']);
});

it('keeps paid documents locked for users a Gate::before() lets through', function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('invoices'));
    Illuminate\Support\Facades\Gate::before(static fn (): bool => true); // Shield's super admin

    $invoice = invoice();
    $invoice->markAsPaid();
    $invoice->refresh();

    $resource = AlfaSix\AlfakitInvoices\Resources\InvoiceResource::class;

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($resource::can('update', $invoice))->toBeFalse()
        ->and($resource::can('delete', $invoice))->toBeFalse()
        ->and($resource::can('recordPayment', $invoice))->toBeFalse()
        ->and($resource::can('view', $invoice))->toBeTrue();
});
