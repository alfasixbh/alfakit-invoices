<?php

declare(strict_types=1);

use AlfaSix\AlfakitInvoices\Enums\InvoiceStatus;
use AlfaSix\AlfakitInvoices\Enums\InvoiceType;
use AlfaSix\AlfakitInvoices\Enums\Rounding;
use AlfaSix\AlfakitInvoices\Events\InvoiceMarkedOverdue;
use AlfaSix\AlfakitInvoices\Events\InvoicePaid;
use AlfaSix\AlfakitInvoices\Events\InvoiceSent;
use AlfaSix\AlfakitInvoices\Events\InvoiceViewed;
use AlfaSix\AlfakitInvoices\Events\PaymentRecorded;
use AlfaSix\AlfakitInvoices\Exceptions\InvalidPayment;
use AlfaSix\AlfakitInvoices\Exceptions\InvalidStatusTransition;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use AlfaSix\AlfakitInvoices\Models\Payment;
use AlfaSix\AlfakitInvoices\Tests\Fixtures\Models\Customer;
use AlfaSix\AlfakitInvoices\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

use function AlfaSix\AlfakitInvoices\Tests\invoice;
use function AlfaSix\AlfakitInvoices\Tests\tax;
use function Pest\Laravel\artisan;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    Carbon::setTestNow('2026-04-01 09:00:00');
});

it('computes and stores totals from its lines on the server', function (): void {
    $vat = tax('VAT', '10');
    $invoice = invoice([], [
        ['description' => 'Design', 'quantity' => '3', 'unit_price' => 12500, 'tax_ids' => [$vat->id]],
        ['description' => 'Hosting', 'quantity' => '1', 'unit_price' => 5000, 'discount_type' => 'percent', 'discount_value' => '20'],
    ]);

    expect($invoice->subtotal)->toBe(42500)
        ->and($invoice->discount_total)->toBe(1000)
        ->and($invoice->tax_total)->toBe(3750)
        ->and($invoice->total)->toBe(45250)
        ->and($invoice->amount_due)->toBe(45250)
        ->and($invoice->tax_breakdown)->toBe([['name' => 'VAT', 'rate' => '10', 'inclusive' => false, 'compound' => false, 'amount' => 3750]])
        ->and($invoice->items[0]->total)->toBe(37500)
        ->and($invoice->items[0]->tax_amount)->toBe(3750)
        ->and($invoice->items[0]->taxes)->toBe([['id' => $vat->id, 'name' => 'VAT', 'rate' => '10', 'inclusive' => false, 'compound' => false]])
        ->and($invoice->currency)->toBe('BHD')
        ->and($invoice->rounding)->toBe(Rounding::PerLine)
        ->and($invoice->due_date?->toDateString())->toBe('2026-05-01')
        ->and($invoice->formatMoney($invoice->total))->toContain('45.250');
});

it('keeps the tax snapshot of issued documents when a rate changes', function (): void {
    $vat = tax('VAT', '10');
    $invoice = invoice([], [['description' => 'Work', 'quantity' => '1', 'unit_price' => 10000, 'tax_ids' => [$vat->id]]]);
    $invoice->recordPayment(5000);

    $vat->update(['rate' => '15']);
    $invoice->recalculate();

    expect($invoice->tax_total)->toBe(1000)->and($invoice->total)->toBe(11000);
});

it('copies the details of a billable customer', function (): void {
    $customer = Customer::query()->create(['company' => 'Initech', 'email' => 'ap@initech.test', 'address' => 'Riffa', 'vat' => 'BH-9']);
    $invoice = Invoice::createWithItems(['billable' => $customer, 'currency' => 'USD'], [['description' => 'X', 'quantity' => '1', 'unit_price' => 100]]);

    expect($invoice->billable?->is($customer))->toBeTrue()
        ->and($invoice->customer_name)->toBe('Initech')
        ->and($invoice->customer_tax_id)->toBe('BH-9');
});

it('moves through sent and viewed once', function (): void {
    Event::fake([InvoiceSent::class, InvoiceViewed::class]);
    $invoice = invoice();

    $invoice->markAsViewed();
    expect($invoice->status)->toBe(InvoiceStatus::Draft);

    $invoice->markAsSent();
    $invoice->markAsViewed();
    $invoice->markAsViewed();

    expect($invoice->status)->toBe(InvoiceStatus::Viewed)
        ->and($invoice->sent_at)->not->toBeNull()
        ->and($invoice->viewed_at)->not->toBeNull();

    Event::assertDispatchedTimes(InvoiceSent::class, 1);
    Event::assertDispatchedTimes(InvoiceViewed::class, 1);
});

it('rejects transitions that are not allowed', function (): void {
    $invoice = invoice();
    $invoice->void();

    expect($invoice->status)->toBe(InvoiceStatus::Void)
        ->and($invoice->amount_due)->toBe(0)
        ->and(fn () => $invoice->transitionTo(InvoiceStatus::Sent))->toThrow(InvalidStatusTransition::class)
        ->and(fn () => $invoice->markAsSent())->toThrow(InvalidStatusTransition::class)
        ->and(fn () => invoice(['type' => InvoiceType::Quote])->transitionTo(InvoiceStatus::Paid))->toThrow(InvalidStatusTransition::class, 'A Quote can\'t be Paid.')
        ->and(InvoiceStatus::Viewed->canTransitionTo(InvoiceStatus::Draft))->toBeFalse()
        ->and(InvoiceStatus::Draft->canTransitionTo(InvoiceStatus::Sent))->toBeTrue();
});

it('records partial and full payments', function (): void {
    Event::fake([PaymentRecorded::class, InvoicePaid::class]);
    $invoice = invoice();
    $invoice->markAsSent();

    $first = $invoice->recordPayment(40000, method: 'bank_transfer', reference: 'TT-1');

    expect($invoice->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($invoice->amount_paid)->toBe(40000)
        ->and($invoice->amount_due)->toBe(60000)
        ->and($first->currency)->toBe('BHD')
        ->and($first->getMethodLabel())->toBe('Bank transfer');

    $invoice->recordPayment(60000);

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->amount_due)->toBe(0)
        ->and($invoice->paid_at)->not->toBeNull()
        ->and($invoice->isEditable())->toBeFalse()
        ->and(Payment::query()->where('invoice_id', $invoice->id)->sum('amount'))->toBe(100000);

    Event::assertDispatchedTimes(PaymentRecorded::class, 2);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

it('refuses payments above the balance, non-positive amounts and payments on quotes', function (): void {
    $invoice = invoice();

    expect(fn () => $invoice->recordPayment(100001))->toThrow(InvalidPayment::class, 'more than the balance due')
        ->and(fn () => $invoice->recordPayment(0))->toThrow(InvalidPayment::class)
        ->and(fn () => invoice(['type' => InvoiceType::Quote])->recordPayment(100))->toThrow(InvalidPayment::class)
        ->and(Payment::query()->count())->toBe(0);
});

it('puts the status back when a payment is removed', function (): void {
    $invoice = invoice();
    $invoice->markAsSent();
    $invoice->markAsViewed();
    $payment = $invoice->markAsPaid();

    $invoice->removePayment($payment);

    expect($invoice->status)->toBe(InvoiceStatus::Viewed)
        ->and($invoice->amount_paid)->toBe(0)
        ->and($invoice->amount_due)->toBe(100000)
        ->and($invoice->paid_at)->toBeNull();
});

it('does not void documents with payments', function (): void {
    $invoice = invoice();
    $invoice->recordPayment(1000);

    expect($invoice->canBeVoided())->toBeFalse()
        ->and(fn () => $invoice->void())->toThrow(InvalidStatusTransition::class, 'credit note');
});

it('marks unpaid invoices past their due date as overdue with the command', function (): void {
    Event::fake([InvoiceMarkedOverdue::class]);

    $late = invoice(['due_date' => '2026-03-31']);
    $late->markAsSent();
    Carbon::setTestNow('2026-02-20');
    $partial = invoice(['due_date' => '2026-03-01']);
    $partial->markAsSent();
    $partial->recordPayment(1000);
    expect($partial->status)->toBe(InvoiceStatus::PartiallyPaid);
    Carbon::setTestNow('2026-04-01 09:00:00');
    $today = invoice(['due_date' => '2026-04-01']);
    $today->markAsSent();
    $draft = invoice(['due_date' => '2026-03-01']);
    $quote = invoice(['type' => InvoiceType::Quote, 'due_date' => '2026-03-01']);
    $quote->markAsSent();
    $paid = invoice(['due_date' => '2026-03-01']);
    $paid->markAsSent();
    $paid->markAsPaid();

    artisan('alfakit-invoices:mark-overdue')->expectsOutputToContain('2 invoices marked as overdue')->assertSuccessful();

    expect($late->refresh()->status)->toBe(InvoiceStatus::Overdue)
        ->and($partial->refresh()->status)->toBe(InvoiceStatus::Overdue)
        ->and($today->refresh()->status)->toBe(InvoiceStatus::Sent)
        ->and($draft->refresh()->status)->toBe(InvoiceStatus::Draft)
        ->and($quote->refresh()->status)->toBe(InvoiceStatus::Sent)
        ->and($paid->refresh()->status)->toBe(InvoiceStatus::Paid);

    Event::assertDispatchedTimes(InvoiceMarkedOverdue::class, 2);

    // Paying an overdue invoice in full settles it.
    $late->markAsPaid();
    expect($late->status)->toBe(InvoiceStatus::Paid);

    artisan('alfakit-invoices:mark-overdue', ['--date' => '2026-04-05'])->expectsOutputToContain('1 invoice marked as overdue');
    expect($today->refresh()->status)->toBe(InvoiceStatus::Overdue);
});

it('schedules the overdue command daily', function (): void {
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'alfakit-invoices:mark-overdue'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('30 0 * * *');
});

it('converts a quote into an invoice once', function (): void {
    $quote = invoice(['type' => InvoiceType::Quote, 'reference' => 'PO-7'], [
        ['description' => 'Setup', 'quantity' => '1', 'unit_price' => 30000],
        ['description' => 'Support', 'quantity' => '12', 'unit_price' => 2000],
    ]);
    $quote->markAsSent();

    $invoice = $quote->convertToInvoice();

    expect($invoice->type)->toBe(InvoiceType::Invoice)
        ->and($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->source_id)->toBe($quote->id)
        ->and($invoice->items)->toHaveCount(2)
        ->and($invoice->total)->toBe($quote->total)
        ->and($invoice->reference)->toBe('PO-7')
        ->and($invoice->number)->toStartWith('INV-')
        ->and($quote->refresh()->converted_at)->not->toBeNull()
        ->and($quote->isEditable())->toBeFalse()
        ->and(fn () => $quote->convertToInvoice())->toThrow(InvalidStatusTransition::class)
        ->and(fn () => $invoice->convertToInvoice())->toThrow(InvalidStatusTransition::class);
});

it('issues a credit note that settles the invoice balance', function (): void {
    $invoice = invoice();
    $invoice->markAsSent();
    $invoice->recordPayment(30000);

    $creditNote = $invoice->createCreditNote();

    expect($creditNote->type)->toBe(InvoiceType::CreditNote)
        ->and($creditNote->source_id)->toBe($invoice->id)
        ->and($creditNote->number)->toStartWith('CN-')
        ->and($creditNote->total)->toBe(100000)
        ->and($creditNote->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->amount_due)->toBe(0)
        ->and($invoice->payments()->where('method', Payment::METHOD_CREDIT_NOTE)->sole()->amount)->toBe(70000)
        ->and(fn () => $invoice->applyCreditNote($creditNote))->toThrow(InvalidPayment::class)
        ->and(fn () => invoice()->createCreditNote())->toThrow(InvalidStatusTransition::class);
});

it('can draft a credit note to apply later', function (): void {
    $invoice = invoice();
    $invoice->markAsSent();

    $creditNote = $invoice->createCreditNote(apply: false);

    expect($creditNote->status)->toBe(InvoiceStatus::Draft)->and($invoice->refresh()->amount_due)->toBe(100000);

    $creditNote->items()->first()?->update(['quantity' => '1']);
    $creditNote->recalculate();
    $invoice->applyCreditNote($creditNote->refresh());

    expect($invoice->refresh()->amount_due)->toBe(50000)
        ->and($invoice->status)->toBe(InvoiceStatus::PartiallyPaid);
});

it('duplicates a document as a new draft', function (): void {
    $original = invoice(['notes' => 'Thanks']);
    $original->markAsSent();

    $copy = $original->duplicate();

    expect($copy->id)->not->toBe($original->id)
        ->and($copy->uuid)->not->toBe($original->uuid)
        ->and($copy->status)->toBe(InvoiceStatus::Draft)
        ->and($copy->number)->not->toBe($original->number)
        ->and($copy->notes)->toBe('Thanks')
        ->and($copy->total)->toBe($original->total)
        ->and($copy->sent_at)->toBeNull();
});
