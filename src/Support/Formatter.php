<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Support;

use AlfaSix\Alfakit\Localization\TextDirection;
use AlfaSix\Alfakit\Support\Currency;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use Carbon\CarbonInterface;
use IntlDateFormatter;
use NumberFormatter;
use Throwable;

/**
 * Locale-aware formatting of a document, with ICU like the panel's `LocaleFormatter`:
 * money in the document's currency (3 decimals for BHD, none for JPY), dates, quantities
 * and rates in the document's locale, and its reading direction.
 */
final class Formatter
{
    public function __construct(
        public readonly string $locale,
        public readonly string $currency,
    ) {}

    public static function for(Invoice $invoice): self
    {
        return new self(
            $invoice->locale !== null && $invoice->locale !== '' ? $invoice->locale : app()->getLocale(),
            $invoice->currency,
        );
    }

    public function money(int $amount): string
    {
        return Currency::format($amount, $this->currency, $this->locale, inMinorUnits: true);
    }

    public function date(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        try {
            $formatter = new IntlDateFormatter($this->locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, $date->getTimezone()->getName());
            $formatted = $formatter->format($date);
        } catch (Throwable) {
            $formatted = false;
        }

        return is_string($formatted) ? $formatted : $date->format('Y-m-d');
    }

    /**
     * A quantity without trailing zeros (`2`, `1.5`), with the locale's digits and separators.
     */
    public function quantity(string|int $quantity): string
    {
        return $this->decimal((string) $quantity);
    }

    /**
     * `7.25` → `7.25%` (`٧٫٢٥٪` in Arabic).
     */
    public function rate(string $rate): string
    {
        try {
            $formatter = new NumberFormatter($this->locale, NumberFormatter::PERCENT);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 4);
            $formatted = $formatter->format((float) $rate / 100);
        } catch (Throwable) {
            $formatted = false;
        }

        return is_string($formatted) ? $formatted : $rate.'%';
    }

    public function direction(): string
    {
        return TextDirection::detect($this->locale)->isRtl() ? 'rtl' : 'ltr';
    }

    public function isRtl(): bool
    {
        return $this->direction() === 'rtl';
    }

    private function decimal(string $value): string
    {
        $value = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;

        try {
            $formatter = new NumberFormatter($this->locale, NumberFormatter::DECIMAL);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 8);
            // Display only: the exact value is kept as a string everywhere else.
            $formatted = $formatter->format((float) $value);
        } catch (Throwable) {
            $formatted = false;
        }

        return is_string($formatted) ? $formatted : $value;
    }
}
