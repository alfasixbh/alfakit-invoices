<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Contracts;

/**
 * Turns the document's HTML into the file customers download and receive by email.
 * Bind your own with `config('alfakit-invoices.pdf.renderer')` or
 * `$this->app->bind(PdfRenderer::class, MyRenderer::class)`.
 */
interface PdfRenderer
{
    /**
     * The file contents for a complete HTML document.
     *
     * @param  array{paper?: string, direction?: string, filename?: string}  $options
     */
    public function render(string $html, array $options = []): string;

    /** `application/pdf`, or `text/html` for the print-ready HTML renderer. */
    public function mimeType(): string;

    /** `pdf` or `html`. */
    public function extension(): string;
}
