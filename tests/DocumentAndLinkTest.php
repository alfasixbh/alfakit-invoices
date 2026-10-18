<?php

declare(strict_types=1);

use AlfaSix\AlfakitInvoices\Contracts\PaymentLinkProvider;
use AlfaSix\AlfakitInvoices\Contracts\PdfRenderer;
use AlfaSix\AlfakitInvoices\Enums\InvoiceStatus;
use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use AlfaSix\AlfakitInvoices\Mail\InvoiceMail;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use AlfaSix\AlfakitInvoices\Pdf\HtmlRenderer;
use AlfaSix\AlfakitInvoices\Support\Document;
use AlfaSix\AlfakitInvoices\Tests\TestCase;
use Illuminate\Support\Carbon;

use function AlfaSix\AlfakitInvoices\Tests\invoice;
use function AlfaSix\AlfakitInvoices\Tests\tax;
use function Pest\Laravel\get;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

/**
 * A renderer that records what it was given and returns a fake PDF.
 */
final class FakePdfRenderer implements PdfRenderer
{
    /** @var array<int, array{html: string, options: array<string, mixed>}> */
    public array $rendered = [];

    public function render(string $html, array $options = []): string
    {
        $this->rendered[] = ['html' => $html, 'options' => $options];

        return '%PDF-fake '.strlen($html);
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

beforeEach(function (): void {
    Carbon::setTestNow('2026-04-01 09:00:00');
});

it('renders print-ready HTML when no PDF library is installed', function (): void {
    $vat = tax('VAT', '10');
    $invoice = invoice(['notes' => 'Pay by bank transfer <script>alert(1)</script>'], [['description' => 'Design <b>work</b>', 'quantity' => '2', 'unit_price' => 12500, 'tax_ids' => [$vat->id]]]);

    $document = Document::for($invoice);
    $html = $document->html();

    expect(app(PdfRenderer::class))->toBeInstanceOf(HtmlRenderer::class)
        ->and($document->filename())->toBe(strtolower($invoice->number).'.html')
        ->and($document->mimeType())->toBe('text/html')
        ->and($html)->toContain('<html lang="en" dir="ltr">')
        ->toContain('Acme Trading W.L.L.')
        ->toContain('VAT-123')
        ->toContain($invoice->number)
        ->toContain('Globex')
        ->toContain('BHD')
        ->toContain('27.500')
        ->toContain('Design &lt;b&gt;work&lt;/b&gt;')
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>alert(1)</script>')
        ->toContain('@page { size: a4');
});

it('renders right to left with localised numbers in the document locale', function (): void {
    $invoice = invoice(['locale' => 'ar']);

    $html = Document::for($invoice)->html();

    expect($html)->toContain('dir="rtl"')
        ->toContain('text-align: right')
        ->toContain('د.ب.')
        ->and(app()->getLocale())->toBe('en');
});

it('uses the bound PDF renderer for downloads and email attachments', function (): void {
    $fake = new FakePdfRenderer;
    app()->instance(PdfRenderer::class, $fake);
    $invoice = invoice();
    $invoice->markAsSent();

    $response = get($invoice->getDownloadUrl())->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="'.strtolower((string) $invoice->number).'.pdf"')
        ->and($response->getContent())->toStartWith('%PDF-fake')
        ->and($fake->rendered)->toHaveCount(1)
        ->and($fake->rendered[0]['options'])->toMatchArray(['paper' => 'a4', 'direction' => 'ltr']);

    $attachments = (new InvoiceMail($invoice))->attachments();

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]->as)->toBe(strtolower((string) $invoice->number).'.pdf')
        ->and($attachments[0]->mime)->toBe('application/pdf');
});

it('picks the configured renderer class', function (): void {
    config()->set('alfakit-invoices.pdf.renderer', FakePdfRenderer::class);
    app()->forgetInstance(PdfRenderer::class);

    expect(app(PdfRenderer::class))->toBeInstanceOf(FakePdfRenderer::class);

    config()->set('alfakit-invoices.pdf.renderer', stdClass::class);

    expect(fn () => app(PdfRenderer::class))->toThrow(InvalidArgumentException::class);
});

it('shows the invoice on its signed link and marks it viewed', function (): void {
    $invoice = invoice();
    $invoice->markAsSent();
    $url = (string) $invoice->getCustomerUrl();

    $response = get($url)->assertOk();

    expect($response->getContent())->toContain($invoice->number)
        ->toContain('Download')
        ->toContain('noindex')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Viewed)
        ->and($invoice->viewed_at)->not->toBeNull();
});

it('adds the opt-in "Powered by" credit to the customer page and the document', function (): void {
    $invoice = invoice();
    $invoice->markAsSent();
    $url = (string) $invoice->getCustomerUrl();

    expect(get($url)->assertOk()->getContent())->not->toContain('data-slot="powered-by"');

    config(['alfakit.credit.public_pages' => true]);

    expect(get($url)->assertOk()->getContent())->toContain('data-slot="powered-by"')
        ->toContain('data:image/svg+xml;base64,')
        ->and(Document::for($invoice)->html())->toContain('data-slot="powered-by"');
});

it('refuses unsigned, tampered and unknown links, and hides drafts', function (): void {
    $invoice = invoice();
    $invoice->markAsSent();
    $url = (string) $invoice->getCustomerUrl();
    $other = invoice();
    $other->markAsSent();

    get(strtok($url, '?'))->assertForbidden();
    get($url.'&preview=1')->assertForbidden();
    get(str_replace($invoice->uuid, $other->uuid, $url))->assertForbidden();

    $draft = invoice();
    get((string) $draft->getCustomerUrl())->assertNotFound();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Sent);
});

it('lets panel previews open drafts without counting a view', function (): void {
    $invoice = invoice();

    get((string) $invoice->getPreviewUrl())->assertOk()->assertSee($invoice->number);

    $invoice->markAsSent();
    get((string) $invoice->getPreviewUrl())->assertOk();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Sent)->and($invoice->viewed_at)->toBeNull();

    Carbon::setTestNow(now()->addMinutes(11));
    get((string) $invoice->getPreviewUrl())->assertOk();
});

it('expires customer links when configured', function (): void {
    config()->set('alfakit-invoices.link_expires_days', 30);
    $invoice = invoice();
    $invoice->markAsSent();
    $url = (string) $invoice->getCustomerUrl();

    get($url)->assertOk();

    Carbon::setTestNow(now()->addDays(31));
    get($url)->assertForbidden();
});

it('offers "Pay now" from the plugin hook only while there is a balance', function (): void {
    InvoicesPlugin::get()->payNowUsing(fn (Invoice $invoice): string => 'https://pay.example.test/checkout/'.$invoice->uuid);
    $invoice = invoice();
    $invoice->markAsSent();

    get((string) $invoice->getCustomerUrl())->assertSee('Pay now')->assertSee('https://pay.example.test/checkout/'.$invoice->uuid);

    $invoice->markAsPaid();

    get((string) $invoice->getCustomerUrl())->assertDontSee('Pay now')->assertSee('Paid in full');
});

it('asks the bound payment link provider, and ignores unsafe URLs', function (): void {
    app()->instance(PaymentLinkProvider::class, new class implements PaymentLinkProvider
    {
        public function paymentUrl(Invoice $invoice): ?string
        {
            return $invoice->customer_name === 'Evil' ? 'javascript:alert(1)' : 'https://gateway.test/'.$invoice->id;
        }
    });

    $invoice = invoice();
    $invoice->markAsSent();
    $evil = invoice(['customer_name' => 'Evil']);
    $evil->markAsSent();

    get((string) $invoice->getCustomerUrl())->assertSee('https://gateway.test/'.$invoice->id);
    get((string) $evil->getCustomerUrl())->assertDontSee('Pay now')->assertDontSee('javascript:', false);
});

it('has no "Pay now" without a hook or a billing provider that supports it', function (): void {
    $invoice = invoice();
    $invoice->markAsSent();

    get((string) $invoice->getCustomerUrl())->assertOk()->assertDontSee('Pay now');
});
