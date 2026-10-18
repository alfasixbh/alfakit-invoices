<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Enums;

use AlfaSix\Alfakit\Support\Contracts\HasColor;
use AlfaSix\Alfakit\Support\Contracts\HasIcon;
use AlfaSix\Alfakit\Support\Contracts\HasLabel;

/**
 * Where a document is in its life. Every change goes through `canTransitionTo()`:
 *
 * - `draft` → sent, paid, partially paid, void
 * - `sent` → viewed, paid, partially paid, overdue, void
 * - `viewed` → paid, partially paid, overdue, void
 * - `partially_paid` → paid, overdue, and back to sent / viewed when a payment is removed
 * - `overdue` → paid, partially paid, void, and back to sent / viewed when the due date moves
 * - `paid` → partially paid, sent, viewed, overdue (only when a payment is removed)
 * - `void` is final
 */
enum InvoiceStatus: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Void = 'void';

    public function getLabel(): string
    {
        return (string) __('alfakit-invoices::invoices.statuses.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'info',
            self::Viewed => 'primary',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
            self::Overdue => 'danger',
            self::Void => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'file-pen',
            self::Sent => 'send',
            self::Viewed => 'eye',
            self::PartiallyPaid => 'circle-dashed',
            self::Paid => 'circle-check',
            self::Overdue => 'alarm-clock',
            self::Void => 'ban',
        };
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Sent, self::Paid, self::PartiallyPaid, self::Void],
            self::Sent => [self::Viewed, self::Paid, self::PartiallyPaid, self::Overdue, self::Void],
            self::Viewed => [self::Paid, self::PartiallyPaid, self::Overdue, self::Void],
            self::PartiallyPaid => [self::Paid, self::Overdue, self::Sent, self::Viewed],
            self::Overdue => [self::Paid, self::PartiallyPaid, self::Sent, self::Viewed, self::Void],
            self::Paid => [self::PartiallyPaid, self::Sent, self::Viewed, self::Overdue],
            self::Void => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return $status === $this || in_array($status, $this->allowedTransitions(), true);
    }

    /** Sent to the customer and still waiting for (full) payment. */
    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /**
     * @return array<int, self>
     */
    public static function open(): array
    {
        return [self::Sent, self::Viewed, self::PartiallyPaid, self::Overdue];
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
