<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Support\Money;

/**
 * The totals of a document, in minor units:
 *
 * - `subtotal`: the line amounts before discounts (as priced),
 * - `discount`: the line discounts,
 * - `tax`: every tax, inclusive ones too (`inclusiveTax` is the part already in the prices),
 * - `total`: `subtotal - discount + (tax - inclusiveTax)`, what the customer pays.
 *
 * `taxes` is the breakdown per rate: `[{key, id, name, rate, inclusive, compound, amount}]`.
 */
final class Totals
{
    /**
     * @param  array<int, LineResult>  $lines
     * @param  array<int, array{key: string, id: int|string|null, name: string, rate: string, inclusive: bool, compound: bool, amount: int}>  $taxes
     */
    public function __construct(
        public readonly string $currency,
        public readonly array $lines,
        public readonly int $subtotal,
        public readonly int $discount,
        public readonly int $tax,
        public readonly int $inclusiveTax,
        public readonly int $total,
        public readonly array $taxes,
    ) {}

    /** The amount before taxes (after discounts). */
    public function net(): int
    {
        return $this->total - $this->tax;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'tax' => $this->tax,
            'inclusive_tax' => $this->inclusiveTax,
            'net' => $this->net(),
            'total' => $this->total,
            'taxes' => $this->taxes,
        ];
    }
}
