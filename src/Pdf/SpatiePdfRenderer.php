<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Pdf;

use AlfaSix\AlfakitInvoices\Contracts\PdfRenderer;
use RuntimeException;

/**
 * PDF files with `spatie/laravel-pdf` (headless Chrome through Browsershot, or its other
 * drivers): full CSS and correct shaping of right-to-left scripts.
 */
class SpatiePdfRenderer implements PdfRenderer
{
    public const BUILDER_CLASS = 'Spatie\LaravelPdf\PdfBuilder';

    /** `spatie/laravel-pdf` is optional (`suggest`): checked before rendering. */
    protected static string $builderClass = self::BUILDER_CLASS;

    public static function isAvailable(): bool
    {
        return class_exists(static::$builderClass);
    }

    public function render(string $html, array $options = []): string
    {
        if (! self::isAvailable()) {
            throw new RuntimeException('Install spatie/laravel-pdf to render invoices with it.');
        }

        $builder = app(static::$builderClass);
        $pdf = $builder->html($html)->format($options['paper'] ?? 'a4')->base64();

        $decoded = is_string($pdf) ? base64_decode($pdf, true) : false;

        return is_string($decoded) ? $decoded : '';
    }

    public function mimeType(): string
    {
        return 'application/pdf';
    }

    public function extension(): string
    {
        return 'pdf';
    }
}
