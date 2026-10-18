<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Models;

use AlfaSix\AlfakitInvoices\AlfakitInvoices;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * A payment towards a document, in its currency's minor units. Payments are recorded
 * through `Invoice::recordPayment()`, which keeps the balance and status in step;
 * `method` `credit_note` is a credit note applied to the invoice.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $amount
 * @property string $currency
 * @property CarbonImmutable $paid_at
 * @property string $method
 * @property string|null $reference
 * @property string|null $notes
 * @property int|null $credit_note_id
 * @property-read Invoice $invoice
 * @property-read Invoice|null $creditNote
 */
class Payment extends Model
{
    public const METHOD_CREDIT_NOTE = 'credit_note';

    protected $table = 'alfakit_invoice_payments';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'integer',
        'paid_at' => 'immutable_date',
    ];

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(AlfakitInvoices::invoiceModel(), 'invoice_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(AlfakitInvoices::invoiceModel(), 'credit_note_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function recordedBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function getMethodLabel(): string
    {
        return self::methodLabel($this->method);
    }

    public static function methodLabel(string $method): string
    {
        $key = 'alfakit-invoices::invoices.payment_methods.'.$method;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : Str::headline($method);
    }

    /**
     * The configured payment methods as select options.
     *
     * @return array<string, string>
     */
    public static function methodOptions(): array
    {
        $methods = config('alfakit-invoices.payment_methods', []);
        $options = [];

        foreach (is_array($methods) ? $methods : [] as $method) {
            if (is_string($method) && $method !== '') {
                $options[$method] = self::methodLabel($method);
            }
        }

        return $options;
    }
}
