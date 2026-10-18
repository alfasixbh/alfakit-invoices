<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Pdf;

use AlfaSix\AlfakitInvoices\Contracts\PdfRenderer;

/**
 * The fallback when no PDF library is installed: the print-ready HTML document itself
 * (A4 / Letter page rules, colours kept when printing). Customers print it or save it as
 * PDF from the browser. Install `spatie/laravel-pdf` or `barryvdh/laravel-dompdf` for
 * real PDF files.
 */
class HtmlRenderer implements PdfRenderer
{
    public function render(string $html, array $options = []): string
    {
        return $html;
    }

    public function mimeType(): string
    {
        return 'text/html';
    }

    public function extension(): string
    {
        return 'html';
    }
}
