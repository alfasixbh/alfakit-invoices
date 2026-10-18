<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Support\Money;

use InvalidArgumentException;

/**
 * A named tax rate as applied to a line (a snapshot: later changes to the tax don't
 * change issued documents). `rate` is a percentage as a decimal string (`"7.25"`).
 *
 * - inclusive: the line price already contains the tax; it is extracted, not added.
 * - compound: charged on the line's net amount plus the taxes before it.
 */
final class TaxRate
{
    public readonly string $rate;

    public function __construct(
        public readonly string $name,
        string|int $rate,
        public readonly bool $inclusive = false,
        public readonly bool $compound = false,
        public readonly int|string|null $id = null,
    ) {
        $rate = trim((string) $rate);

        if (preg_match('/^\d+(\.\d+)?$/', $rate) !== 1) {
            throw new InvalidArgumentException("The tax rate [{$rate}] must be a non-negative decimal.");
        }

        $this->rate = self::normalizeRate($rate);
    }

    public static function make(string $name, string|int $rate, bool $inclusive = false, bool $compound = false, int|string|null $id = null): self
    {
        return new self($name, $rate, $inclusive, $compound, $id);
    }

    /**
     * @param  array<string, mixed>  $data  `['name' => …, 'rate' => …, 'inclusive' => …, 'compound' => …, 'id' => …]`
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;

        return new self(
            is_scalar($data['name'] ?? null) ? (string) $data['name'] : '',
            is_scalar($data['rate'] ?? null) ? (string) $data['rate'] : '0',
            (bool) ($data['inclusive'] ?? false),
            (bool) ($data['compound'] ?? false),
            is_int($id) || is_string($id) ? $id : null,
        );
    }

    /**
     * @return array{id: int|string|null, name: string, rate: string, inclusive: bool, compound: bool}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'rate' => $this->rate, 'inclusive' => $this->inclusive, 'compound' => $this->compound];
    }

    /** Lines with the same key are added up in the document's tax breakdown. */
    public function key(): string
    {
        return implode('|', [$this->id ?? '', $this->name, $this->rate, $this->inclusive ? 'i' : 'e', $this->compound ? 'c' : 's']);
    }

    public function fraction(): Rational
    {
        return Rational::percent($this->rate);
    }

    private static function normalizeRate(string $rate): string
    {
        if (! str_contains($rate, '.')) {
            return ltrim($rate, '0') === '' ? '0' : ltrim($rate, '0');
        }

        [$whole, $fraction] = explode('.', $rate, 2);
        $whole = ltrim($whole, '0') === '' ? '0' : ltrim($whole, '0');
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }
}
