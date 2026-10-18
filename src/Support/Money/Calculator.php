<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Support\Money;

use AlfaSix\Alfakit\Support\Currency;
use AlfaSix\AlfakitInvoices\Enums\DiscountType;
use AlfaSix\AlfakitInvoices\Enums\Rounding;

/**
 * Document totals on exact fractions, rounded half away from zero to the currency's
 * minor unit (2 decimals for USD, 3 for BHD, none for JPY). Nothing here uses floats.
 *
 * Per line:
 *
 * 1. `amount = quantity × unit price`, rounded.
 * 2. `discount`: a percentage of the amount, or a fixed amount, rounded and capped at the amount.
 * 3. Inclusive taxes are extracted from `amount - discount`: the net is
 *    `(amount - discount) / ((1 + Σ simple rates) × Π (1 + compound rate))`.
 * 4. Simple taxes are charged on the net, compound taxes on the net plus every tax before
 *    them (inclusive taxes first, then exclusive ones, simple before compound).
 *
 * ```php
 * $totals = Calculator::calculate([
 *     Line::make('2', 1500, [TaxRate::make('VAT', '10')]),
 * ], 'BHD');
 *
 * $totals->total; // 3300 (3.300 BHD)
 * ```
 */
final class Calculator
{
    /**
     * @param  array<int, Line>  $lines
     */
    public static function calculate(array $lines, string $currency, Rounding $rounding = Rounding::PerLine): Totals
    {
        $currency = Currency::normalize($currency);
        $decimals = Currency::decimals($currency);

        $results = [];
        $subtotal = 0;
        $discountTotal = 0;
        $grossTotal = 0;

        /** @var array<string, TaxRate> $rates */
        $rates = [];
        /** @var array<string, int> $rounded */
        $rounded = [];
        /** @var array<string, Rational> $exact */
        $exact = [];

        foreach ($lines as $line) {
            $amount = Rational::of(self::quantity($line->quantity))->times($line->unitPrice)->roundHalfUp();
            $discount = self::discount($line, $amount, $decimals);
            $gross = $amount - $discount;

            $lineTaxes = [];

            foreach (self::taxes($gross, $line->taxes) as $key => [$rate, $tax]) {
                $rates[$key] ??= $rate;
                $lineTaxes[$key] = ($lineTaxes[$key] ?? 0) + $tax->roundHalfUp();
                $rounded[$key] = ($rounded[$key] ?? 0) + $tax->roundHalfUp();
                $exact[$key] = ($exact[$key] ?? Rational::zero())->plus($tax);
            }

            $results[] = new LineResult($amount, $discount, $gross, array_sum($lineTaxes), $lineTaxes);

            $subtotal += $amount;
            $discountTotal += $discount;
            $grossTotal += $gross;
        }

        $breakdown = [];
        $taxTotal = 0;
        $inclusiveTotal = 0;

        foreach ($rates as $key => $rate) {
            $amount = $rounding === Rounding::PerTotal ? $exact[$key]->roundHalfUp() : $rounded[$key];

            $breakdown[] = ['key' => $key, ...$rate->toArray(), 'amount' => $amount];
            $taxTotal += $amount;

            if ($rate->inclusive) {
                $inclusiveTotal += $amount;
            }
        }

        return new Totals(
            currency: $currency,
            lines: $results,
            subtotal: $subtotal,
            discount: $discountTotal,
            tax: $taxTotal,
            inclusiveTax: $inclusiveTotal,
            total: $grossTotal + $taxTotal - $inclusiveTotal,
            taxes: $breakdown,
        );
    }

    /**
     * The exact taxes of a line amount (after its discount), in the order they apply.
     *
     * @param  array<int, TaxRate>  $taxes
     * @return array<string, array{0: TaxRate, 1: Rational}>
     */
    public static function taxes(int $gross, array $taxes): array
    {
        $ordered = [
            ...array_filter($taxes, static fn (TaxRate $tax): bool => $tax->inclusive && ! $tax->compound),
            ...array_filter($taxes, static fn (TaxRate $tax): bool => $tax->inclusive && $tax->compound),
            ...array_filter($taxes, static fn (TaxRate $tax): bool => ! $tax->inclusive && ! $tax->compound),
            ...array_filter($taxes, static fn (TaxRate $tax): bool => ! $tax->inclusive && $tax->compound),
        ];

        // The inclusive factor: (1 + Σ simple inclusive rates) × Π (1 + compound inclusive rate).
        $factor = Rational::one();

        foreach ($ordered as $tax) {
            if ($tax->inclusive && ! $tax->compound) {
                $factor = $factor->plus($tax->fraction());
            }
        }

        foreach ($ordered as $tax) {
            if ($tax->inclusive && $tax->compound) {
                $factor = $factor->times(Rational::one()->plus($tax->fraction()));
            }
        }

        $net = Rational::of($gross)->dividedBy($factor);
        $cumulative = $net;
        $results = [];
        $pendingSimple = Rational::zero();

        foreach ($ordered as $tax) {
            if ($tax->compound) {
                // Simple taxes before a compound one are part of its base.
                $cumulative = $cumulative->plus($pendingSimple);
                $pendingSimple = Rational::zero();
                $amount = $cumulative->times($tax->fraction());
                $cumulative = $cumulative->plus($amount);
            } else {
                $amount = $net->times($tax->fraction());
                $pendingSimple = $pendingSimple->plus($amount);
            }

            $key = $tax->key();
            $results[$key] = [$tax, isset($results[$key]) ? $results[$key][1]->plus($amount) : $amount];
        }

        return $results;
    }

    /**
     * A quantity as a decimal string (`1.5`, `"2"`, `"0,75"` all work).
     */
    public static function quantity(mixed $quantity): string
    {
        if (is_int($quantity)) {
            return (string) $quantity;
        }

        if (is_float($quantity)) {
            return rtrim(rtrim(number_format($quantity, self::quantityDecimals(), '.', ''), '0'), '.') ?: '0';
        }

        $quantity = is_scalar($quantity) ? str_replace([' ', ','], ['', '.'], trim((string) $quantity)) : '';

        return preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $quantity) === 1 ? $quantity : '0';
    }

    public static function quantityDecimals(): int
    {
        $decimals = config('alfakit-invoices.quantity_decimals', 4);

        return is_numeric($decimals) ? max(0, min(8, (int) $decimals)) : 4;
    }

    private static function discount(Line $line, int $amount, int $decimals): int
    {
        if ($line->discountType === null || $line->discountValue === null || trim($line->discountValue) === '') {
            return 0;
        }

        $value = Rational::of(self::quantity($line->discountValue));

        if ($value->sign() <= 0) {
            return 0;
        }

        $discount = match ($line->discountType) {
            DiscountType::Percent => Rational::of($amount)->times($value->dividedBy(100))->roundHalfUp(),
            DiscountType::Fixed => $value->times((int) (10 ** $decimals))->roundHalfUp(),
        };

        // Never more than the line itself (a discount can't turn a line negative).
        return $amount >= 0 ? min($discount, $amount) : 0;
    }
}
