<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Support;

use AlfaSix\AlfakitInvoices\AlfakitInvoices;
use AlfaSix\AlfakitInvoices\Contracts\PdfRenderer;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Throwable;

/**
 * The printable document of an invoice: HTML from a Blade view (`pdf.view`, override it
 * with `php artisan vendor:publish --tag=alfakit-invoices-views`), turned into a file by
 * the bound `PdfRenderer`. Rendered in the document's locale and direction.
 */
final class Document
{
    public function __construct(
        public readonly Invoice $invoice,
        private readonly PdfRenderer $renderer,
    ) {}

    public static function for(Invoice $invoice): self
    {
        return new self($invoice, app(PdfRenderer::class));
    }

    /**
     * The complete HTML page.
     */
    public function html(): string
    {
        return $this->inLocale(fn (): string => View::make($this->view(), $this->data())->render());
    }

    /**
     * The view data, also used by the customer page around the document.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $invoice = $this->invoice->loadMissing(['items', 'payments', 'source']);
        $plugin = AlfakitInvoices::plugin($invoice);
        $formatter = $invoice->formatter();
        $branding = $plugin->getBranding($invoice);
        $rtl = $formatter->isRtl();

        return [
            'invoice' => $invoice,
            'items' => $invoice->items,
            'payments' => $invoice->payments,
            'formatter' => $formatter,
            'branding' => $branding,
            'logo' => $this->embeddedLogo($branding->logo),
            'seller' => $plugin->getSeller($invoice->getTenant()),
            'direction' => $formatter->direction(),
            'start' => $rtl ? 'right' : 'left',
            'end' => $rtl ? 'left' : 'right',
            'fontStack' => $branding->fontStack($rtl),
            'paper' => $this->paper(),
            'locale' => $formatter->locale,
            // "Powered by AlfaKit · Alfa Six" (`InvoicesPlugin::poweredBy()`), off by default.
            'poweredBy' => $plugin->getPoweredByHtml(embedded: true),
        ];
    }

    /**
     * The file contents (a PDF, or HTML with the HTML renderer).
     */
    public function render(): string
    {
        return $this->renderer->render($this->html(), [
            'paper' => $this->paper(),
            'direction' => $this->invoice->formatter()->direction(),
            'filename' => $this->filename(),
        ]);
    }

    public function filename(): string
    {
        return $this->invoice->getFilename($this->renderer->extension());
    }

    public function mimeType(): string
    {
        return $this->renderer->mimeType();
    }

    public function renderer(): PdfRenderer
    {
        return $this->renderer;
    }

    private function view(): string
    {
        $view = config('alfakit-invoices.pdf.view', 'alfakit-invoices::document');

        return is_string($view) && $view !== '' ? $view : 'alfakit-invoices::document';
    }

    private function paper(): string
    {
        $paper = config('alfakit-invoices.pdf.paper', 'a4');

        return is_string($paper) && in_array(strtolower($paper), ['a4', 'letter', 'legal', 'a5'], true) ? strtolower($paper) : 'a4';
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inLocale(callable $callback): mixed
    {
        $locale = $this->invoice->locale;
        $previous = App::getLocale();

        if (! is_string($locale) || $locale === '' || $locale === $previous) {
            return $callback();
        }

        App::setLocale($locale);

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * A logo inside `public/` is embedded as a data URI, so PDF renderers never fetch URLs
     * and emailed documents keep their logo. Other logos stay links.
     */
    private function embeddedLogo(?string $logo): ?string
    {
        if ($logo === null) {
            return null;
        }

        try {
            $path = parse_url($logo, PHP_URL_PATH);
            $host = parse_url($logo, PHP_URL_HOST);
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

            if (! is_string($path) || ($host !== null && $host !== $appHost)) {
                return $logo;
            }

            $public = realpath(public_path());
            $file = realpath(public_path(ltrim(rawurldecode($path), '/')));

            if ($public === false || $file === false || ! str_starts_with($file, $public.DIRECTORY_SEPARATOR) || ! is_file($file) || filesize($file) > 512 * 1024) {
                return $logo;
            }

            $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
                'svg' => 'image/svg+xml',
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => null,
            };

            $contents = $mime === null ? false : file_get_contents($file);

            return is_string($contents) ? 'data:'.$mime.';base64,'.base64_encode($contents) : $logo;
        } catch (Throwable) {
            return $logo;
        }
    }
}
