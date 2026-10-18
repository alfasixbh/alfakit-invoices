<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Models;

use AlfaSix\AlfakitInvoices\AlfakitInvoices;
use AlfaSix\AlfakitInvoices\Enums\DiscountType;
use AlfaSix\AlfakitInvoices\Support\Money\Line;
use AlfaSix\AlfakitInvoices\Support\Money\TaxRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a document. `unit_price`, `amount`, `discount_amount`, `tax_amount` and
 * `total` are minor units; the last four are written by `Invoice::recalculate()` only.
 * `taxes` is the snapshot of the rates in `tax_ids` taken while the document was editable.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $sort
 * @property string $description
 * @property string $quantity
 * @property int $unit_price
 * @property DiscountType|null $discount_type
 * @property string|null $discount_value
 * @property array<int, int|string>|null $tax_ids
 * @property array<int, array<string, mixed>>|null $taxes
 * @property int $amount
 * @property int $discount_amount
 * @property int $tax_amount
 * @property int $total
 * @property-read Invoice $invoice
 */
class InvoiceItem extends Model
{
    protected $table = 'alfakit_invoice_items';

    protected $guarded = ['id'];

    protected $casts = [
        'sort' => 'integer',
        'quantity' => 'decimal:4',
        'unit_price' => 'integer',
        'discount_type' => DiscountType::class,
        'discount_value' => 'decimal:4',
        'tax_ids' => 'array',
        'taxes' => 'array',
        'amount' => 'integer',
        'discount_amount' => 'integer',
        'tax_amount' => 'integer',
        'total' => 'integer',
    ];

    protected $attributes = [
        'quantity' => 1,
        'unit_price' => 0,
        'amount' => 0,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total' => 0,
    ];

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(AlfakitInvoices::invoiceModel(), 'invoice_id');
    }

    /**
     * @return array<int, TaxRate>
     */
    public function taxRates(): array
    {
        return array_map(static fn (array $tax): TaxRate => TaxRate::fromArray($tax), array_values(array_filter($this->taxes ?? [], is_array(...))));
    }

    public function toLine(): Line
    {
        return new Line(
            quantity: (string) $this->quantity,
            unitPrice: (int) $this->unit_price,
            discountType: $this->discount_type,
            discountValue: $this->discount_value === null ? null : (string) $this->discount_value,
            taxes: $this->taxRates(),
        );
    }

    /**
     * Copy the current rates of `tax_ids` (taxes of the document's tenant only).
     */
    public function refreshTaxSnapshot(?Model $tenant): void
    {
        $ids = array_values(array_filter(array_map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '', $this->tax_ids ?? []), static fn (string $id): bool => $id !== ''));
        $model = AlfakitInvoices::taxModel();

        $taxes = $ids === [] ? collect() : $model::query()->forTenant($tenant)->whereKey($ids)->orderBy('sort')->orderBy('id')->get();

        $this->tax_ids = $taxes->map(static fn (Tax $tax): mixed => $tax->getKey())->values()->all();
        $this->taxes = $taxes->map(static fn (Tax $tax): array => $tax->toRate()->toArray())->values()->all();
    }

    /**
     * Names of the line's taxes: `VAT 10%, Service 5%`.
     */
    public function getTaxLabel(): string
    {
        return implode(', ', array_map(static fn (TaxRate $tax): string => $tax->name.' '.$tax->rate.'%', $this->taxRates()));
    }
}
