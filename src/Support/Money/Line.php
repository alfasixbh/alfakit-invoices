<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Support\Money;

use AlfaSix\AlfakitInvoices\Enums\DiscountType;

/**
 * One line to calculate: `quantity` (a decimal string), the unit price in minor units,
 * an optional discount and the taxes that apply.
 */
final class Line
{
    /**
     * @param  array<int, TaxRate>  $taxes
     */
    public function __construct(
        public readonly string $quantity,
        public readonly int $unitPrice,
        public readonly ?DiscountType $discountType = null,
        public readonly ?string $discountValue = null,
        public readonly array $taxes = [],
    ) {}

    /**
     * @param  array<int, TaxRate>  $taxes
     */
    public static function make(int|string $quantity, int $unitPrice, array $taxes = [], ?DiscountType $discountType = null, int|string|null $discountValue = null): self
    {
        return new self((string) $quantity, $unitPrice, $discountType, $discountValue === null ? null : (string) $discountValue, $taxes);
    }
}
