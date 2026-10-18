<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Models;

use AlfaSix\Alfakit\Support\Currency;
use AlfaSix\AlfakitInvoices\AlfakitInvoices;
use AlfaSix\AlfakitInvoices\Contracts\InvoiceRecipient;
use AlfaSix\AlfakitInvoices\Enums\InvoiceStatus;
use AlfaSix\AlfakitInvoices\Enums\InvoiceType;
use AlfaSix\AlfakitInvoices\Enums\Rounding;
use AlfaSix\AlfakitInvoices\Events\InvoiceMarkedOverdue;
use AlfaSix\AlfakitInvoices\Events\InvoicePaid;
use AlfaSix\AlfakitInvoices\Events\InvoiceSent;
use AlfaSix\AlfakitInvoices\Events\InvoiceViewed;
use AlfaSix\AlfakitInvoices\Events\InvoiceVoided;
use AlfaSix\AlfakitInvoices\Events\PaymentRecorded;
use AlfaSix\AlfakitInvoices\Events\PaymentRemoved;
use AlfaSix\AlfakitInvoices\Exceptions\InvalidPayment;
use AlfaSix\AlfakitInvoices\Exceptions\InvalidStatusTransition;
use AlfaSix\AlfakitInvoices\Support\Formatter;
use AlfaSix\AlfakitInvoices\Support\Money\Calculator;
use AlfaSix\AlfakitInvoices\Support\Money\Totals;
use AlfaSix\AlfakitInvoices\Support\Numbering;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * An invoice, quote, credit note or receipt. Money columns are integers of the
 * currency's minor unit; totals are only ever computed here (`recalculate()`), never
 * taken from a form.
 *
 * ```php
 * $invoice = Invoice::createWithItems(
 *     ['customer_name' => 'Acme W.L.L.', 'currency' => 'BHD'],
 *     [['description' => 'Design', 'quantity' => '10', 'unit_price' => 25000, 'tax_ids' => [$vat->id]]],
 *     tenant: $team,
 * );
 *
 * $invoice->markAsSent();
 * $invoice->recordPayment(100000, method: 'bank_transfer', reference: 'TT-1234');
 * ```
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $tenant_type
 * @property int|string|null $tenant_id
 * @property string|null $panel_id
 * @property InvoiceType $type
 * @property InvoiceStatus $status
 * @property string|null $number
 * @property string|null $reference
 * @property CarbonImmutable $issue_date
 * @property CarbonImmutable|null $due_date
 * @property string $currency
 * @property Rounding $rounding
 * @property string|null $locale
 * @property string|null $billable_type
 * @property int|string|null $billable_id
 * @property string|null $customer_name
 * @property string|null $customer_email
 * @property string|null $customer_phone
 * @property string|null $customer_address
 * @property string|null $customer_tax_id
 * @property string|null $notes
 * @property string|null $terms
 * @property int $subtotal
 * @property int $discount_total
 * @property int $tax_total
 * @property int $total
 * @property int $amount_paid
 * @property int $amount_due
 * @property array<int, array<string, mixed>>|null $tax_breakdown
 * @property int|null $source_id
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $viewed_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $voided_at
 * @property CarbonImmutable|null $converted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, InvoiceItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Payment> $payments
 * @property-read Model|null $tenant
 * @property-read Model|null $billable
 * @property-read Invoice|null $source
 *
 * @method static Builder<static> ofType(InvoiceType $type)
 * @method static Builder<static> open()
 * @method static Builder<static> forTenant(?Model $tenant)
 */
class Invoice extends Model
{
    protected $table = 'alfakit_invoices';

    protected $guarded = ['id', 'uuid'];

    protected $casts = [
        'type' => InvoiceType::class,
        'status' => InvoiceStatus::class,
        'rounding' => Rounding::class,
        'issue_date' => 'immutable_date',
        'due_date' => 'immutable_date',
        'subtotal' => 'integer',
        'discount_total' => 'integer',
        'tax_total' => 'integer',
        'total' => 'integer',
        'amount_paid' => 'integer',
        'amount_due' => 'integer',
        'tax_breakdown' => 'array',
        'sent_at' => 'immutable_datetime',
        'viewed_at' => 'immutable_datetime',
        'paid_at' => 'immutable_datetime',
        'voided_at' => 'immutable_datetime',
        'converted_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    protected $attributes = [
        'subtotal' => 0,
        'discount_total' => 0,
        'tax_total' => 0,
        'total' => 0,
        'amount_paid' => 0,
        'amount_due' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(static function (Invoice $invoice): void {
            $invoice->uuid = (string) Str::uuid();
            $invoice->applyDefaults();

            if ($invoice->number === null && AlfakitInvoices::plugin($invoice)->assignsNumbersOnCreate()) {
                $invoice->number = Numbering::next($invoice->type, $invoice->getTenant(), $invoice->issue_date);
            }
        });
    }

    /**
     * Defaults of a new document: type, status, dates, currency, rounding, locale, panel.
     */
    public function applyDefaults(): void
    {
        $plugin = AlfakitInvoices::plugin($this);

        $this->setAttribute('type', $this->getAttributeValue('type') ?? InvoiceType::Invoice);
        $this->setAttribute('status', $this->getAttributeValue('status') ?? InvoiceStatus::Draft);
        $this->setAttribute('issue_date', $this->getAttributeValue('issue_date') ?? Carbon::today());
        $this->currency = Currency::normalize(is_string($this->getAttributeValue('currency')) && $this->getAttributeValue('currency') !== '' ? $this->getAttributeValue('currency') : $plugin->getDefaultCurrency());
        $this->setAttribute('rounding', $this->getAttributeValue('rounding') ?? $plugin->getRounding());
        $this->locale ??= app()->getLocale();
        $this->panel_id ??= alfakit()->getCurrentPanel()?->getId();

        if ($this->getAttributeValue('due_date') === null) {
            $days = match ($this->type) {
                InvoiceType::Invoice => $plugin->getDueDays(),
                InvoiceType::Quote => $plugin->getQuoteValidDays(),
                default => null,
            };

            if ($days !== null) {
                $this->due_date = $this->issue_date->addDays($days);
            }
        }
    }

    /**
     * Create a document with its lines and compute its totals.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $items  `description`, `quantity`, `unit_price` (minor units), `discount_type`, `discount_value`, `tax_ids`
     */
    public static function createWithItems(array $attributes, array $items = [], ?Model $tenant = null): static
    {
        return DB::transaction(static function () use ($attributes, $items, $tenant): static {
            $invoice = new static;
            $invoice->fill($attributes);

            if ($tenant !== null) {
                $invoice->tenant()->associate($tenant);
            }

            if (($billable = $attributes['billable'] ?? null) instanceof Model) {
                $invoice->offsetUnset('billable');
                $invoice->billable()->associate($billable);
                $invoice->fillCustomerFrom($billable, overwrite: false);
            }

            $invoice->save();

            foreach (array_values($items) as $index => $item) {
                $line = $invoice->items()->make();
                $line->fill([...$item, 'sort' => $item['sort'] ?? $index]);
                $line->save();
            }

            return $invoice->recalculate();
        });
    }

    // ---------------------------------------------------------------- Relationships

    /**
     * @return MorphTo<Model, $this>
     */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The customer model the document is billed to, if any (its details are copied onto
     * the `customer_*` columns).
     *
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(AlfakitInvoices::itemModel(), 'invoice_id')->orderBy('sort')->orderBy('id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(AlfakitInvoices::paymentModel(), 'invoice_id')->orderBy('paid_at')->orderBy('id');
    }

    /**
     * The quote an invoice was converted from, or the invoice a credit note is for.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(AlfakitInvoices::invoiceModel(), 'source_id');
    }

    /**
     * Documents made from this one (the invoice of a quote, credit notes of an invoice).
     *
     * @return HasMany<Invoice, $this>
     */
    public function derived(): HasMany
    {
        return $this->hasMany(AlfakitInvoices::invoiceModel(), 'source_id');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->derived()->where('type', InvoiceType::CreditNote->value);
    }

    public function getTenant(): ?Model
    {
        if ($this->tenant_type === null || $this->tenant_id === null) {
            return null;
        }

        $tenant = $this->tenant;

        return $tenant instanceof Model ? $tenant : null;
    }

    // ---------------------------------------------------------------- Scopes

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOfType(Builder $query, InvoiceType $type): void
    {
        $query->where($query->qualifyColumn('type'), $type->value);
    }

    /**
     * Sent and not fully paid (sent, viewed, partially paid, overdue).
     *
     * @param  Builder<static>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn($query->qualifyColumn('status'), array_map(static fn (InvoiceStatus $status): string => $status->value, InvoiceStatus::open()));
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeForTenant(Builder $query, ?Model $tenant): void
    {
        if ($tenant === null) {
            $query->whereNull($query->qualifyColumn('tenant_type'));

            return;
        }

        $query->where($query->qualifyColumn('tenant_type'), $tenant->getMorphClass())->where($query->qualifyColumn('tenant_id'), $tenant->getKey());
    }

    // ---------------------------------------------------------------- Display

    public function getDisplayNumber(): string
    {
        return $this->number ?? (string) __('alfakit-invoices::invoices.labels.draft_number');
    }

    /**
     * `Invoice INV-2026-00042`.
     */
    public function getTitle(): string
    {
        return $this->type->getLabel().' '.$this->getDisplayNumber();
    }

    public function formatter(): Formatter
    {
        return Formatter::for($this);
    }

    public function formatMoney(int $amount): string
    {
        return $this->formatter()->money($amount);
    }

    public function getCustomerLabel(): string
    {
        return $this->customer_name ?? $this->customer_email ?? (string) __('alfakit-invoices::invoices.labels.no_customer');
    }

    // ---------------------------------------------------------------- Customer

    /**
     * Copy a customer model's details onto the document (`InvoiceRecipient`, else the
     * `name`, `email`, `phone`, `address` and `tax_id` attributes).
     */
    public function fillCustomerFrom(Model $billable, bool $overwrite = true): static
    {
        $details = $billable instanceof InvoiceRecipient ? $billable->getInvoiceRecipientDetails() : [
            'name' => $billable->getAttribute('name'),
            'email' => $billable->getAttribute('email'),
            'phone' => $billable->getAttribute('phone'),
            'address' => $billable->getAttribute('address'),
            'tax_id' => $billable->getAttribute('tax_id'),
        ];

        foreach (['name', 'email', 'phone', 'address', 'tax_id'] as $key) {
            $value = $details[$key] ?? null;
            $attribute = 'customer_'.$key;

            if (is_scalar($value) && (string) $value !== '' && ($overwrite || $this->getAttribute($attribute) === null)) {
                $this->setAttribute($attribute, (string) $value);
            }
        }

        return $this;
    }

    // ---------------------------------------------------------------- Totals

    /**
     * The totals of the current lines, without saving anything.
     */
    public function calculateTotals(): Totals
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        return Calculator::calculate($items->map(static fn (InvoiceItem $item) => $item->toLine())->values()->all(), $this->currency, $this->rounding);
    }

    /**
     * Compute and save the line and document totals. While the document is editable the
     * line taxes are copied again from the tax rates (tenant's taxes only).
     */
    public function recalculate(): static
    {
        $items = $this->items()->get();
        $editable = $this->isEditable();
        $tenant = $this->getTenant();

        if ($editable) {
            $items->each(static fn (InvoiceItem $item) => $item->refreshTaxSnapshot($tenant));
        }

        $totals = Calculator::calculate($items->map(static fn (InvoiceItem $item) => $item->toLine())->values()->all(), $this->currency, $this->rounding);

        foreach ($items->values() as $index => $item) {
            $line = $totals->lines[$index];

            $item->forceFill([
                'amount' => $line->amount,
                'discount_amount' => $line->discount,
                'tax_amount' => $line->tax,
                'total' => $line->total,
            ]);

            if ($item->isDirty()) {
                $item->save();
            }
        }

        $this->setRelation('items', $items);

        $this->forceFill([
            'subtotal' => $totals->subtotal,
            'discount_total' => $totals->discount,
            'tax_total' => $totals->tax,
            'total' => $totals->total,
            'tax_breakdown' => array_map(static fn (array $tax): array => [
                'name' => $tax['name'],
                'rate' => $tax['rate'],
                'inclusive' => $tax['inclusive'],
                'compound' => $tax['compound'],
                'amount' => $tax['amount'],
            ], $totals->taxes),
            'amount_due' => $totals->total - (int) $this->amount_paid,
        ])->save();

        if ((int) $this->amount_paid > 0) {
            $this->syncPaymentStatus();
        }

        return $this;
    }

    // ---------------------------------------------------------------- State

    /**
     * Drafts, and sent documents without payments, can still be changed.
     */
    public function isEditable(): bool
    {
        if ($this->status === InvoiceStatus::Draft) {
            return true;
        }

        return in_array($this->status, [InvoiceStatus::Sent, InvoiceStatus::Viewed, InvoiceStatus::Overdue], true)
            && (int) $this->amount_paid === 0
            && $this->converted_at === null;
    }

    public function isPayable(): bool
    {
        return $this->type->isPayable()
            && $this->status !== InvoiceStatus::Void
            && (int) $this->amount_due > 0;
    }

    /**
     * Past its due date (invoices only), compared in the app's timezone.
     */
    public function isPastDue(?CarbonInterface $today = null): bool
    {
        if (! $this->type->canBeOverdue() || $this->due_date === null) {
            return false;
        }

        $today ??= Carbon::today();

        return $this->due_date->startOfDay()->lt($today->copy()->startOfDay());
    }

    public function canBeSent(): bool
    {
        return $this->status !== InvoiceStatus::Void && $this->converted_at === null;
    }

    public function canBeVoided(): bool
    {
        return $this->status->canTransitionTo(InvoiceStatus::Void) && $this->status !== InvoiceStatus::Void && (int) $this->amount_paid === 0;
    }

    public function canBeConverted(): bool
    {
        return $this->type === InvoiceType::Quote && $this->status !== InvoiceStatus::Void && $this->converted_at === null;
    }

    public function canBeCredited(): bool
    {
        return $this->type === InvoiceType::Invoice && ! in_array($this->status, [InvoiceStatus::Draft, InvoiceStatus::Void], true) && (int) $this->total > 0;
    }

    /**
     * Move to a status, or throw `InvalidStatusTransition`.
     */
    public function transitionTo(InvoiceStatus $status, bool $save = true): static
    {
        $from = $this->status;

        if ($status === InvoiceStatus::Void && $from !== InvoiceStatus::Void && (int) $this->amount_paid > 0) {
            throw InvalidStatusTransition::because('void_with_payments');
        }

        if (! $from->canTransitionTo($status)) {
            throw InvalidStatusTransition::make($from, $status);
        }

        $allowed = match ($this->type) {
            InvoiceType::Quote => ! in_array($status, [InvoiceStatus::Paid, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue], true),
            InvoiceType::CreditNote => ! in_array($status, [InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue], true),
            InvoiceType::Receipt => $status !== InvoiceStatus::Overdue,
            InvoiceType::Invoice => true,
        };

        if (! $allowed) {
            throw InvalidStatusTransition::forType($this->type, $status);
        }

        $this->status = $status;

        if ($save) {
            $this->save();
        }

        return $this;
    }

    /**
     * Give the document its number if it doesn't have one yet (`numbering.assign = issue`).
     */
    public function assignNumber(): static
    {
        if ($this->number === null) {
            $this->number = Numbering::next($this->type, $this->getTenant(), $this->issue_date);
        }

        return $this;
    }

    /**
     * Mark as sent to the customer (a draft gets its number). Sending again keeps the status.
     */
    public function markAsSent(): static
    {
        if (! $this->canBeSent()) {
            throw InvalidStatusTransition::make($this->status, InvoiceStatus::Sent);
        }

        $this->assignNumber();

        if ($this->status === InvoiceStatus::Draft) {
            $this->transitionTo(InvoiceStatus::Sent, save: false);
        }

        $this->sent_at = CarbonImmutable::now();
        $this->save();

        InvoiceSent::dispatch($this);

        return $this;
    }

    /**
     * The customer opened the document: a sent one becomes viewed (once).
     */
    public function markAsViewed(): static
    {
        if ($this->status === InvoiceStatus::Draft || $this->status === InvoiceStatus::Void) {
            return $this;
        }

        $first = $this->viewed_at === null;
        $this->viewed_at ??= CarbonImmutable::now();

        if ($this->status === InvoiceStatus::Sent) {
            $this->transitionTo(InvoiceStatus::Viewed, save: false);
        }

        if ($this->isDirty()) {
            $this->save();
        }

        if ($first) {
            InvoiceViewed::dispatch($this);
        }

        return $this;
    }

    /**
     * Record a payment (minor units) towards the balance. Throws `InvalidPayment` for a
     * document that doesn't take payments, a non-positive amount, or more than is due.
     */
    public function recordPayment(int $amount, ?CarbonInterface $paidAt = null, string $method = 'other', ?string $reference = null, ?string $notes = null, ?Invoice $creditNote = null, ?Model $recordedBy = null): Payment
    {
        return DB::transaction(function () use ($amount, $paidAt, $method, $reference, $notes, $creditNote, $recordedBy): Payment {
            // Re-read the balance under a lock so concurrent payments can't overpay.
            $fresh = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($fresh instanceof static) {
                $this->setRawAttributes($fresh->getAttributes(), sync: true);
            }

            if (! $this->type->isPayable()) {
                throw InvalidPayment::because('not_payable', ['type' => $this->type->getLabel()]);
            }

            if ($this->status === InvoiceStatus::Void) {
                throw InvalidPayment::because('void');
            }

            if ($amount <= 0) {
                throw InvalidPayment::because('amount_positive');
            }

            if ($amount > (int) $this->amount_due) {
                throw InvalidPayment::because('amount_exceeds', ['due' => $this->formatMoney((int) $this->amount_due)]);
            }

            $model = AlfakitInvoices::paymentModel();
            $payment = new $model;
            $payment->forceFill([
                'invoice_id' => $this->getKey(),
                'amount' => $amount,
                'currency' => $this->currency,
                'paid_at' => ($paidAt ?? Carbon::today())->format('Y-m-d'),
                'method' => $creditNote !== null ? Payment::METHOD_CREDIT_NOTE : $method,
                'reference' => $reference ?? $creditNote?->number,
                'notes' => $notes,
                'credit_note_id' => $creditNote?->getKey(),
            ]);

            if ($recordedBy !== null) {
                $payment->recordedBy()->associate($recordedBy);
            }

            $payment->save();

            $this->assignNumber();
            $this->amount_paid = (int) $this->amount_paid + $amount;
            $this->amount_due = (int) $this->total - (int) $this->amount_paid;
            $this->syncPaymentStatus(save: false);
            $this->save();
            $this->unsetRelation('payments');

            PaymentRecorded::dispatch($this, $payment);

            if ($this->status === InvoiceStatus::Paid) {
                InvoicePaid::dispatch($this);
            }

            return $payment;
        });
    }

    /**
     * Pay the whole balance.
     */
    public function markAsPaid(?CarbonInterface $paidAt = null, string $method = 'other', ?string $reference = null, ?Model $recordedBy = null): Payment
    {
        return $this->recordPayment((int) $this->amount_due, $paidAt, $method, $reference, recordedBy: $recordedBy);
    }

    /**
     * Delete a payment and put the balance and status back.
     */
    public function removePayment(Payment $payment): static
    {
        if ((int) $payment->invoice_id !== (int) $this->getKey()) {
            throw InvalidPayment::because('foreign_payment');
        }

        DB::transaction(function () use ($payment): void {
            $payment->delete();

            $this->amount_paid = (int) $this->payments()->sum('amount');
            $this->amount_due = (int) $this->total - (int) $this->amount_paid;
            $this->syncPaymentStatus(save: false);
            $this->save();
            $this->unsetRelation('payments');
        });

        PaymentRemoved::dispatch($this, $payment);

        return $this;
    }

    /**
     * Derive the status from the balance: paid, partially paid (overdue when past due),
     * or back to sent / viewed / overdue when payments were removed.
     */
    public function syncPaymentStatus(bool $save = true): static
    {
        if ($this->status === InvoiceStatus::Void) {
            return $this;
        }

        $paid = (int) $this->amount_paid;
        $total = (int) $this->total;

        $status = match (true) {
            $paid > 0 && $paid >= $total => InvoiceStatus::Paid,
            $paid > 0 => $this->isPastDue() ? InvoiceStatus::Overdue : InvoiceStatus::PartiallyPaid,
            in_array($this->status, [InvoiceStatus::Paid, InvoiceStatus::PartiallyPaid], true) => $this->isPastDue() ? InvoiceStatus::Overdue : ($this->viewed_at !== null ? InvoiceStatus::Viewed : InvoiceStatus::Sent),
            default => $this->status,
        };

        // A partially paid document that was already overdue stays overdue.
        if ($status === InvoiceStatus::PartiallyPaid && $this->status === InvoiceStatus::Overdue && $this->isPastDue()) {
            $status = InvoiceStatus::Overdue;
        }

        if ($status !== $this->status) {
            $this->transitionTo($status, save: false);
        }

        $this->paid_at = $status === InvoiceStatus::Paid ? ($this->paid_at ?? CarbonImmutable::now()) : null;

        if ($save && $this->isDirty()) {
            $this->save();
        }

        return $this;
    }

    /**
     * Mark as overdue when unpaid past its due date. Returns whether it changed.
     */
    public function markAsOverdueIfPastDue(?CarbonInterface $today = null): bool
    {
        if (! in_array($this->status, [InvoiceStatus::Sent, InvoiceStatus::Viewed, InvoiceStatus::PartiallyPaid], true)
            || (int) $this->amount_due <= 0
            || ! $this->isPastDue($today)) {
            return false;
        }

        $this->transitionTo(InvoiceStatus::Overdue);

        InvoiceMarkedOverdue::dispatch($this);

        return true;
    }

    /**
     * Cancel the document. Documents with payments can't be voided (issue a credit note).
     */
    public function void(): static
    {
        $this->transitionTo(InvoiceStatus::Void, save: false);
        $this->voided_at = CarbonImmutable::now();
        $this->amount_due = 0;
        $this->save();

        InvoiceVoided::dispatch($this);

        return $this;
    }

    // ---------------------------------------------------------------- Copies

    /**
     * A new draft of the same type with the same customer and lines, dated today.
     */
    public function duplicate(?InvoiceType $type = null, ?int $sourceId = null): static
    {
        return DB::transaction(function () use ($type, $sourceId): static {
            $copy = new static;
            $copy->forceFill([
                'type' => $type ?? $this->type,
                'status' => InvoiceStatus::Draft,
                'currency' => $this->currency,
                'rounding' => $this->rounding,
                'locale' => $this->locale,
                'panel_id' => $this->panel_id,
                'reference' => $this->reference,
                'billable_type' => $this->billable_type,
                'billable_id' => $this->billable_id,
                'customer_name' => $this->customer_name,
                'customer_email' => $this->customer_email,
                'customer_phone' => $this->customer_phone,
                'customer_address' => $this->customer_address,
                'customer_tax_id' => $this->customer_tax_id,
                'notes' => $this->notes,
                'terms' => $this->terms,
                'tenant_type' => $this->tenant_type,
                'tenant_id' => $this->tenant_id,
                'source_id' => $sourceId,
                'issue_date' => Carbon::today(),
            ]);
            $copy->save();

            foreach ($this->items()->get() as $item) {
                $copy->items()->create($item->only(['sort', 'description', 'quantity', 'unit_price', 'discount_type', 'discount_value', 'tax_ids', 'taxes']));
            }

            return $copy->recalculate();
        });
    }

    /**
     * Turn a quote into an invoice (once). The quote keeps its status and links to it.
     */
    public function convertToInvoice(): static
    {
        if (! $this->canBeConverted()) {
            throw InvalidStatusTransition::because('cannot_convert');
        }

        return DB::transaction(function (): static {
            $invoice = $this->duplicate(InvoiceType::Invoice, (int) $this->getKey());

            $this->assignNumber();
            $this->converted_at = CarbonImmutable::now();
            $this->save();

            return $invoice;
        });
    }

    /**
     * A credit note for this invoice with its lines. With `$apply`, it settles as much of
     * the balance as it covers right away (recorded as a `credit_note` payment) and is
     * marked paid; otherwise it stays a draft to edit and apply later with `applyCreditNote()`.
     */
    public function createCreditNote(bool $apply = true): static
    {
        if (! $this->canBeCredited()) {
            throw InvalidStatusTransition::because('cannot_credit');
        }

        return DB::transaction(function () use ($apply): static {
            $creditNote = $this->duplicate(InvoiceType::CreditNote, (int) $this->getKey());

            if ($apply) {
                $this->applyCreditNote($creditNote);
            }

            return $creditNote->refresh();
        });
    }

    /**
     * Settle this invoice's balance with a credit note (up to the balance). The credit
     * note gets its number and is marked paid (allocated).
     */
    public function applyCreditNote(Invoice $creditNote): ?Payment
    {
        if ($creditNote->type !== InvoiceType::CreditNote || (int) $creditNote->source_id !== (int) $this->getKey() || $creditNote->status === InvoiceStatus::Void) {
            throw InvalidPayment::because('invalid_credit_note');
        }

        $payments = AlfakitInvoices::paymentModel();

        if ($payments::query()->where('credit_note_id', $creditNote->getKey())->exists()) {
            throw InvalidPayment::because('credit_note_applied');
        }

        $amount = min((int) $creditNote->total, (int) $this->fresh()?->amount_due);

        $creditNote->assignNumber();
        $creditNote->sent_at ??= CarbonImmutable::now();
        $creditNote->transitionTo(InvoiceStatus::Paid, save: false);
        $creditNote->paid_at = CarbonImmutable::now();
        $creditNote->amount_due = 0;
        $creditNote->save();

        return $amount > 0 ? $this->recordPayment($amount, Carbon::today(), Payment::METHOD_CREDIT_NOTE, creditNote: $creditNote) : null;
    }

    // ---------------------------------------------------------------- Links

    /**
     * The signed link customers open (outside the panel). Opening it marks the document
     * as viewed. Never expires unless `link_expires_days` is set.
     */
    public function getCustomerUrl(): ?string
    {
        return $this->signedRoute('show');
    }

    /**
     * The signed download link of the customer page.
     */
    public function getDownloadUrl(): ?string
    {
        return $this->signedRoute('download');
    }

    /**
     * A short-lived link for panel users: opening it doesn't count as a customer view.
     */
    public function getPreviewUrl(bool $download = false): ?string
    {
        if (! AlfakitInvoices::hasRoute($download ? 'download' : 'show')) {
            return null;
        }

        $minutes = config('alfakit-invoices.preview_minutes', 10);

        return URL::temporarySignedRoute(
            AlfakitInvoices::routeName($download ? 'download' : 'show'),
            Carbon::now()->addMinutes(is_numeric($minutes) ? max(1, (int) $minutes) : 10),
            ['invoice' => $this->uuid, 'preview' => 1],
        );
    }

    private function signedRoute(string $name): ?string
    {
        if (! AlfakitInvoices::hasRoute($name)) {
            return null;
        }

        $days = config('alfakit-invoices.link_expires_days');
        $route = AlfakitInvoices::routeName($name);

        return is_numeric($days) && (int) $days > 0
            ? URL::temporarySignedRoute($route, ($this->sent_at ?? CarbonImmutable::now())->addDays((int) $days), ['invoice' => $this->uuid])
            : URL::signedRoute($route, ['invoice' => $this->uuid]);
    }

    /**
     * `INV-2026-00042.pdf`.
     */
    public function getFilename(string $extension = 'pdf'): string
    {
        $name = Str::slug($this->number ?? $this->type->getLabel().'-'.$this->uuid, '-', null);

        return ($name !== '' ? $name : 'document').'.'.$extension;
    }
}
