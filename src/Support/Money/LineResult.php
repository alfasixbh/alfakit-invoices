<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Support\Money;

/**
 * A calculated line, in minor units. `total` is the line amount after its discount, as
 * priced (it contains inclusive taxes and not exclusive ones); `tax` is the line's tax,
 * rounded on the line, for display.
 */
final class LineResult
{
    /**
     * @param  array<string, int>  $taxes  Rounded tax per `TaxRate::key()`
     */
    public function __construct(
        public readonly int $amount,
        public readonly int $discount,
        public readonly int $total,
        public readonly int $tax,
        public readonly array $taxes,
    ) {}
}
