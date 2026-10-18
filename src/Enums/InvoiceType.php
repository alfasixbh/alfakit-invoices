<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Enums;

use AlfaSix\Alfakit\Support\Contracts\HasColor;
use AlfaSix\Alfakit\Support\Contracts\HasIcon;
use AlfaSix\Alfakit\Support\Contracts\HasLabel;

/**
 * The kind of document. Each type has its own number sequence. Quotes never take
 * payments and are converted into invoices; credit notes are issued against an
 * invoice and may settle part of its balance; receipts record a sale that was paid
 * on the spot.
 */
enum InvoiceType: string implements HasColor, HasIcon, HasLabel
{
    case Invoice = 'invoice';
    case Quote = 'quote';
    case CreditNote = 'credit_note';
    case Receipt = 'receipt';

    public function getLabel(): string
    {
        return (string) __('alfakit-invoices::invoices.types.'.$this->value);
    }

    public function getPluralLabel(): string
    {
        return (string) __('alfakit-invoices::invoices.types_plural.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Invoice => 'primary',
            self::Quote => 'info',
            self::CreditNote => 'warning',
            self::Receipt => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Invoice => 'file-text',
            self::Quote => 'file-pen-line',
            self::CreditNote => 'file-minus',
            self::Receipt => 'receipt',
        };
    }

    /** Invoices and receipts take payments. */
    public function isPayable(): bool
    {
        return $this === self::Invoice || $this === self::Receipt;
    }

    /** Only invoices fall overdue. */
    public function canBeOverdue(): bool
    {
        return $this === self::Invoice;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }
}
