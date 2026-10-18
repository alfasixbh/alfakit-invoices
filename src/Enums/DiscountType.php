<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Enums;

use AlfaSix\Alfakit\Support\Contracts\HasLabel;

/**
 * A line discount: a percentage of the line amount, or a fixed amount in the
 * document's currency (major units, e.g. `2.500` BHD).
 */
enum DiscountType: string implements HasLabel
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    public function getLabel(): string
    {
        return (string) __('alfakit-invoices::invoices.discount_types.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [self::Percent->value => self::Percent->getLabel(), self::Fixed->value => self::Fixed->getLabel()];
    }
}
