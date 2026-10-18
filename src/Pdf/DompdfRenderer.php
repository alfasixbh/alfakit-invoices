<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Pdf;

use AlfaSix\AlfakitInvoices\Contracts\PdfRenderer;
use RuntimeException;

/**
 * PDF files with Dompdf (`dompdf/dompdf`, also installed by `barryvdh/laravel-dompdf`).
 * Remote files are off: logos are embedded by the document builder, so rendering never
 * fetches URLs. Dompdf has no text shaping for Arabic or Persian; prefer
 * `SpatiePdfRenderer` (headless Chrome) for right-to-left documents.
 */
class DompdfRenderer implements PdfRenderer
{
    public const DOMPDF_CLASS = 'Dompdf\Dompdf';

    public const OPTIONS_CLASS = 'Dompdf\Options';

    /** `dompdf/dompdf` is optional (`suggest`): checked before rendering. */
    protected static string $dompdfClass = self::DOMPDF_CLASS;

    protected static string $optionsClass = self::OPTIONS_CLASS;

    public static function isAvailable(): bool
    {
        return class_exists(static::$dompdfClass) && class_exists(static::$optionsClass);
    }

    public function render(string $html, array $options = []): string
    {
        if (! self::isAvailable()) {
            throw new RuntimeException('Install dompdf/dompdf (or barryvdh/laravel-dompdf) to render invoices with Dompdf.');
        }

        $optionsClass = static::$optionsClass;
        $dompdfClass = static::$dompdfClass;

        $settings = new $optionsClass;
        $settings->set('isRemoteEnabled', false);
        $settings->set('isPhpEnabled', false);
        $settings->set('isJavascriptEnabled', false);
        $settings->set('defaultFont', 'DejaVu Sans');
        $settings->set('chroot', public_path());

        $dompdf = new $dompdfClass($settings);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($options['paper'] ?? 'a4', 'portrait');
        $dompdf->render();

        $output = $dompdf->output();

        return is_string($output) ? $output : '';
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
