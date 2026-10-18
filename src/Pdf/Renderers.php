<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Pdf;

use AlfaSix\AlfakitInvoices\Contracts\PdfRenderer;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Picks the `PdfRenderer`: `config('alfakit-invoices.pdf.renderer')` when set, else the
 * first installed driver (spatie/laravel-pdf, then Dompdf), else print-ready HTML.
 */
final class Renderers
{
    public static function resolve(Container $app): PdfRenderer
    {
        $configured = config('alfakit-invoices.pdf.renderer');

        if (is_string($configured) && $configured !== '') {
            $renderer = $app->make($configured);

            if (! $renderer instanceof PdfRenderer) {
                throw new InvalidArgumentException("The invoices PDF renderer [{$configured}] must implement [".PdfRenderer::class.'].');
            }

            return $renderer;
        }

        return match (true) {
            SpatiePdfRenderer::isAvailable() => new SpatiePdfRenderer,
            DompdfRenderer::isAvailable() => new DompdfRenderer,
            default => new HtmlRenderer,
        };
    }
}
