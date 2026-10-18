<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Enums;

use AlfaSix\Alfakit\Support\Contracts\HasLabel;

/**
 * When taxes are rounded to the currency's minor unit (half away from zero).
 *
 * - `PerLine`: each line's tax is rounded, and the document's tax is the sum of the
 *   rounded line taxes (what most accounting software prints).
 * - `PerTotal`: taxes are summed exactly per rate over all lines and rounded once
 *   (required by some tax authorities, never off by a cent on many small lines).
 *
 * Line amounts and discounts are always rounded per line: they are printed on it.
 */
enum Rounding: string implements HasLabel
{
    case PerLine = 'per_line';
    case PerTotal = 'per_total';

    public function getLabel(): string
    {
        return (string) __('alfakit-invoices::invoices.rounding.'.$this->value);
    }
}
