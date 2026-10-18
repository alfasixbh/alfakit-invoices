<?php

declare(strict_types=1);

use AlfaSix\AlfakitInvoices\Enums\DiscountType;
use AlfaSix\AlfakitInvoices\Enums\Rounding;
use AlfaSix\AlfakitInvoices\Support\Money\Calculator;
use AlfaSix\AlfakitInvoices\Support\Money\Line;
use AlfaSix\AlfakitInvoices\Support\Money\Rational;
use AlfaSix\AlfakitInvoices\Support\Money\TaxRate;

uses(AlfaSix\AlfakitInvoices\Tests\TestCase::class);

it('adds an exclusive tax on top of the line amount', function (): void {
    $totals = Calculator::calculate([Line::make('2', 1500, [TaxRate::make('VAT', '10')])], 'USD');

    expect($totals->subtotal)->toBe(3000)
        ->and($totals->tax)->toBe(300)
        ->and($totals->inclusiveTax)->toBe(0)
        ->and($totals->total)->toBe(3300)
        ->and($totals->net())->toBe(3000)
        ->and($totals->lines[0]->total)->toBe(3000)
        ->and($totals->lines[0]->tax)->toBe(300)
        ->and($totals->taxes[0])->toMatchArray(['name' => 'VAT', 'rate' => '10', 'inclusive' => false, 'amount' => 300]);
});

it('extracts an inclusive tax from the price without changing the total', function (): void {
    // 11.00 including 10% VAT: 10.00 net + 1.00 tax.
    $totals = Calculator::calculate([Line::make('1', 1100, [TaxRate::make('VAT', '10', inclusive: true)])], 'USD');

    expect($totals->total)->toBe(1100)
        ->and($totals->tax)->toBe(100)
        ->and($totals->inclusiveTax)->toBe(100)
        ->and($totals->net())->toBe(1000)
        ->and($totals->subtotal)->toBe(1100);
});

it('rounds inclusive extraction half away from zero', function (): void {
    // 10.00 including 5%: 952.380… net, 47.619… tax → 0.48.
    $totals = Calculator::calculate([Line::make('1', 1000, [TaxRate::make('VAT', '5', inclusive: true)])], 'USD');

    expect($totals->tax)->toBe(48)->and($totals->total)->toBe(1000)->and($totals->net())->toBe(952);
});

it('charges compound taxes on the net plus the taxes before them', function (): void {
    // 100.00 + 5% GST (5.00) + 10% compound PST on 105.00 (10.50) = 115.50.
    $totals = Calculator::calculate([Line::make('1', 10000, [
        TaxRate::make('PST', '10', compound: true),
        TaxRate::make('GST', '5'),
    ])], 'CAD');

    expect($totals->total)->toBe(11550)
        ->and(collect($totals->taxes)->pluck('amount', 'name')->all())->toBe(['GST' => 500, 'PST' => 1050]);
});

it('extracts compound inclusive taxes with the compounded factor', function (): void {
    // 115.50 including 5% and 10% compound: net 100.00.
    $totals = Calculator::calculate([Line::make('1', 11550, [
        TaxRate::make('GST', '5', inclusive: true),
        TaxRate::make('PST', '10', inclusive: true, compound: true),
    ])], 'CAD');

    expect($totals->total)->toBe(11550)
        ->and($totals->net())->toBe(10000)
        ->and(collect($totals->taxes)->pluck('amount', 'name')->all())->toBe(['GST' => 500, 'PST' => 1050]);
});

it('applies a percentage discount before taxes', function (): void {
    $totals = Calculator::calculate([Line::make('4', 2500, [TaxRate::make('VAT', '10')], DiscountType::Percent, '12.5')], 'USD');

    // 100.00 − 12.50 = 87.50, VAT 8.75.
    expect($totals->subtotal)->toBe(10000)
        ->and($totals->discount)->toBe(1250)
        ->and($totals->tax)->toBe(875)
        ->and($totals->total)->toBe(9625);
});

it('applies a fixed discount in major units and caps it at the line amount', function (): void {
    $fixed = Calculator::calculate([Line::make('1', 10000, [], DiscountType::Fixed, '15.50')], 'USD');
    $capped = Calculator::calculate([Line::make('1', 1000, [], DiscountType::Fixed, '25')], 'USD');
    $negative = Calculator::calculate([Line::make('1', 1000, [], DiscountType::Percent, '-10')], 'USD');

    expect($fixed->discount)->toBe(1550)->and($fixed->total)->toBe(8450)
        ->and($capped->discount)->toBe(1000)->and($capped->total)->toBe(0)
        ->and($negative->discount)->toBe(0);
});

it('rounds per line or per total', function (): void {
    // Three lines of 0.05 with 10% tax: 0.005 each.
    $lines = array_fill(0, 3, Line::make('1', 5, [TaxRate::make('VAT', '10')]));

    $perLine = Calculator::calculate($lines, 'USD', Rounding::PerLine);
    $perTotal = Calculator::calculate($lines, 'USD', Rounding::PerTotal);

    // Per line: 0.01 × 3; per total: round(0.015) = 0.02.
    expect($perLine->tax)->toBe(3)->and($perLine->total)->toBe(18)
        ->and($perTotal->tax)->toBe(2)->and($perTotal->total)->toBe(17)
        ->and($perTotal->lines[0]->tax)->toBe(1);
});

it('handles zero-decimal currencies like JPY', function (): void {
    // ¥1,235 × 3 with 8% inclusive consumption tax: ¥3,705 total, ¥274.4… tax → ¥274.
    $totals = Calculator::calculate([Line::make('3', 1235, [TaxRate::make('消費税', '8', inclusive: true)], DiscountType::Fixed, '5')], 'JPY');

    expect($totals->subtotal)->toBe(3705)
        ->and($totals->discount)->toBe(5)
        ->and($totals->total)->toBe(3700)
        ->and($totals->tax)->toBe(274);
});

it('handles three-decimal currencies like BHD', function (): void {
    // 2.5 × 12.345 BHD = 30.8625 → 30.863; 10% VAT = 3.0863 → 3.086.
    $totals = Calculator::calculate([Line::make('2.5', 12345, [TaxRate::make('VAT', '10')])], 'BHD');

    expect($totals->subtotal)->toBe(30863)
        ->and($totals->tax)->toBe(3086)
        ->and($totals->total)->toBe(33949);
});

it('adds up the same tax over several lines and keeps different rates apart', function (): void {
    $vat = TaxRate::make('VAT', '10', id: 1);
    $service = TaxRate::make('Service', '5', id: 2);

    $totals = Calculator::calculate([
        Line::make('1', 1000, [$vat]),
        Line::make('1', 2000, [$vat, $service]),
        Line::make('1', 500),
    ], 'USD');

    expect($totals->taxes)->toHaveCount(2)
        ->and(collect($totals->taxes)->pluck('amount', 'name')->all())->toBe(['VAT' => 300, 'Service' => 100])
        ->and($totals->total)->toBe(3900);
});

it('supports fractional tax rates and quantities exactly', function (): void {
    // 1.333 × 9.99 = 13.31667 → 13.32; 7.25% = 0.96570 → 0.97.
    $totals = Calculator::calculate([Line::make('1.333', 999, [TaxRate::make('Sales tax', '7.25')])], 'USD');

    expect($totals->subtotal)->toBe(1332)->and($totals->tax)->toBe(97)->and($totals->total)->toBe(1429);
});

it('computes an empty document as zero', function (): void {
    $totals = Calculator::calculate([], 'EUR');

    expect($totals->total)->toBe(0)->and($totals->taxes)->toBe([])->and($totals->currency)->toBe('EUR');
});

it('does exact rational arithmetic without floats', function (): void {
    expect(Rational::of('0.1')->plus('0.2')->compareTo('0.3'))->toBe(0)
        ->and(Rational::of('2.5')->roundHalfUp())->toBe(3)
        ->and(Rational::of('-2.5')->roundHalfUp())->toBe(-3)
        ->and(Rational::of('2.4999')->roundHalfUp())->toBe(2)
        ->and(Rational::of(1)->dividedBy(3)->toDecimal(4))->toBe('0.3333')
        ->and(Rational::of('99999999999999999999')->times(10)->toDecimal(0))->toBe('999999999999999999990');
});

it('rejects invalid tax rates and numbers', function (): void {
    expect(fn () => TaxRate::make('Bad', '-5'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Rational::of('1e5'))->toThrow(InvalidArgumentException::class)
        ->and(Calculator::quantity('1,5'))->toBe('1.5')
        ->and(Calculator::quantity('abc'))->toBe('0')
        ->and(Calculator::quantity(2.75))->toBe('2.75');
});
