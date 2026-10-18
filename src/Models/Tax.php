<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Models;

use AlfaSix\AlfakitInvoices\AlfakitInvoices;
use AlfaSix\AlfakitInvoices\Support\Money\TaxRate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A named tax rate offered on invoice lines ("VAT 10%"). `is_inclusive`: prices already
 * contain it; `is_compound`: charged on the net plus the taxes before it; `is_default`:
 * preselected on new lines. Lines keep a snapshot, so editing a tax never changes issued
 * documents.
 *
 * @property int $id
 * @property string|null $tenant_type
 * @property int|string|null $tenant_id
 * @property string $name
 * @property string $rate
 * @property bool $is_inclusive
 * @property bool $is_compound
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort
 *
 * @method static Builder<static> active()
 * @method static Builder<static> forTenant(?Model $tenant)
 */
class Tax extends Model
{
    protected $table = 'alfakit_invoice_taxes';

    protected $guarded = ['id'];

    protected $casts = [
        'rate' => 'decimal:4',
        'is_inclusive' => 'boolean',
        'is_compound' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    protected $attributes = [
        'is_inclusive' => false,
        'is_compound' => false,
        'is_default' => false,
        'is_active' => true,
        'sort' => 0,
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Taxes of a tenant (or the ones without a tenant).
     *
     * @param  Builder<static>  $query
     */
    public function scopeForTenant(Builder $query, ?Model $tenant): void
    {
        if ($tenant === null) {
            $query->whereNull('tenant_type')->whereNull('tenant_id');

            return;
        }

        $query->where('tenant_type', $tenant->getMorphClass())->where('tenant_id', $tenant->getKey());
    }

    public function toRate(): TaxRate
    {
        return new TaxRate($this->name, (string) $this->rate, $this->is_inclusive, $this->is_compound, $this->getKey());
    }

    /**
     * `VAT (10%, included)`.
     */
    public function getOptionLabel(): string
    {
        $flags = array_filter([
            rtrim(rtrim((string) $this->rate, '0'), '.').'%',
            $this->is_inclusive ? __('alfakit-invoices::invoices.taxes.inclusive_short') : null,
            $this->is_compound ? __('alfakit-invoices::invoices.taxes.compound_short') : null,
        ]);

        return $this->name.' ('.implode(', ', $flags).')';
    }

    /**
     * Active taxes of a tenant as select options.
     *
     * @return array<int|string, string>
     */
    public static function options(?Model $tenant): array
    {
        $model = AlfakitInvoices::taxModel();

        return $model::query()->forTenant($tenant)->active()->orderBy('sort')->orderBy('name')->get()
            ->mapWithKeys(static fn (Tax $tax): array => [$tax->getKey() => $tax->getOptionLabel()])
            ->all();
    }

    /**
     * Ids of the tenant's default taxes.
     *
     * @return array<int, string>
     */
    public static function defaultIds(?Model $tenant): array
    {
        $model = AlfakitInvoices::taxModel();

        return $model::query()->forTenant($tenant)->active()->where('is_default', true)->orderBy('sort')->pluck('id')
            ->map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '')
            ->all();
    }
}
