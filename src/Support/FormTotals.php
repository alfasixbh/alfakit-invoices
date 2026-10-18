<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Support;

use AlfaSix\Alfakit\Support\Currency;
use AlfaSix\AlfakitInvoices\AlfakitInvoices;
use AlfaSix\AlfakitInvoices\Enums\DiscountType;
use AlfaSix\AlfakitInvoices\Enums\Rounding;
use AlfaSix\AlfakitInvoices\Models\Tax;
use AlfaSix\AlfakitInvoices\Support\Money\Calculator;
use AlfaSix\AlfakitInvoices\Support\Money\Line;
use AlfaSix\AlfakitInvoices\Support\Money\Rational;
use AlfaSix\AlfakitInvoices\Support\Money\TaxRate;
use AlfaSix\AlfakitInvoices\Support\Money\Totals;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Throwable;
use WeakMap;

/**
 * The live totals of the invoice form, computed on the server from the form state on
 * every refresh (the line fields are `live()`). Only the tenant's own tax rates count;
 * what is saved is computed again by `Invoice::recalculate()`, never taken from here.
 */
final class FormTotals
{
    /** @var WeakMap<object, array{0: string, 1: Totals|null}>|null */
    private static ?WeakMap $memo = null;

    /**
     * The totals for a form, computed once for each state of it: the memo is keyed by the
     * form's schema object, so it goes away with the request.
     *
     * @param  array<int, mixed>  $state
     * @param  Closure(): ?Totals  $calculate
     */
    public static function remember(object $form, array $state, Closure $calculate): ?Totals
    {
        self::$memo ??= new WeakMap;
        $hash = md5(serialize($state));
        $cached = self::$memo[$form] ?? null;

        if ($cached !== null && $cached[0] === $hash) {
            return $cached[1];
        }

        $totals = $calculate();
        self::$memo[$form] = [$hash, $totals];

        return $totals;
    }

    /**
     * @param  array<int|string, mixed>  $items  The repeater state
     */
    public static function calculate(array $items, mixed $currency, mixed $rounding, ?Model $tenant): ?Totals
    {
        try {
            $currency = Currency::normalize(is_string($currency) && $currency !== '' ? $currency : alfakit()->getCurrency());
        } catch (Throwable) {
            return null;
        }

        $lines = [];
        $cache = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $lines[] = self::line($item, $currency, $tenant, $cache);
            }
        }

        $rounding = $rounding instanceof Rounding ? $rounding : (Rounding::tryFrom(is_string($rounding) ? $rounding : '') ?? AlfakitInvoices::plugin()->getRounding());

        return Calculator::calculate($lines, $currency, $rounding);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, TaxRate|null>  $cache  Tax rates already loaded, by id
     */
    public static function line(array $item, string $currency, ?Model $tenant, array &$cache = []): Line
    {
        $discountType = $item['discount_type'] ?? null;
        $discountType = $discountType instanceof DiscountType ? $discountType : DiscountType::tryFrom(is_string($discountType) ? $discountType : '');
        $discountValue = $item['discount_value'] ?? null;

        return new Line(
            quantity: Calculator::quantity($item['quantity'] ?? '0'),
            unitPrice: self::minorUnits($item['unit_price'] ?? null, $currency),
            discountType: $discountType,
            discountValue: is_scalar($discountValue) && (string) $discountValue !== '' ? Calculator::quantity($discountValue) : null,
            taxes: self::taxes($item['tax_ids'] ?? [], $tenant, $cache),
        );
    }

    /**
     * A money field's state (major units, `"12.500"`) as minor units, exactly.
     */
    public static function minorUnits(mixed $amount, string $currency): int
    {
        if (is_int($amount)) {
            return $amount * (10 ** Currency::decimals($currency));
        }

        $amount = Calculator::quantity($amount);

        return Rational::of($amount)->times((int) (10 ** Currency::decimals($currency)))->roundHalfUp();
    }

    /**
     * @param  array<string, TaxRate|null>  $cache
     * @return array<int, TaxRate>
     */
    private static function taxes(mixed $ids, ?Model $tenant, array &$cache): array
    {
        $rates = [];
        $model = AlfakitInvoices::taxModel();

        foreach (is_array($ids) ? $ids : [$ids] as $id) {
            if (! is_scalar($id) || (string) $id === '') {
                continue;
            }

            $key = (string) $id;

            if (! array_key_exists($key, $cache)) {
                $tax = $model::query()->forTenant($tenant)->whereKey($id)->first();
                $cache[$key] = $tax instanceof Tax ? $tax->toRate() : null;
            }

            if ($cache[$key] !== null) {
                $rates[] = $cache[$key];
            }
        }

        return $rates;
    }
}
