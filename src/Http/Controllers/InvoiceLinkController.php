<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Http\Controllers;

use AlfaSix\AlfakitInvoices\AlfakitInvoices;
use AlfaSix\AlfakitInvoices\Enums\InvoiceStatus;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use AlfaSix\AlfakitInvoices\Support\Document;
use AlfaSix\AlfakitInvoices\Support\PayNow;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\View;

/**
 * The customer side of an invoice, behind signed URLs (the `signed` middleware): the
 * invoice page and its download. Customers need no account; the unguessable UUID and the
 * signature are the credential. Opening the page marks a sent invoice as viewed, unless
 * the link is a panel preview (`preview=1`, part of the signature).
 */
class InvoiceLinkController extends Controller
{
    public function show(Request $request, string $invoice): Response
    {
        $model = $this->find($invoice);
        $preview = $request->boolean('preview');

        if (! $preview) {
            $model->markAsViewed();
        }

        $document = Document::for($model);
        $data = $document->data();

        $html = View::make(AlfakitInvoices::view('public.show'), [
            ...$data,
            'payUrl' => $preview ? null : PayNow::url($model),
            'downloadUrl' => $preview ? $model->getPreviewUrl(download: true) : $model->getDownloadUrl(),
            'isPdf' => $document->renderer()->extension() === 'pdf',
        ])->render();

        return $this->secure(new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']));
    }

    public function download(Request $request, string $invoice): Response
    {
        $document = Document::for($this->find($invoice));
        $filename = $document->filename();

        return $this->secure(new Response($document->render(), 200, [
            'Content-Type' => $document->mimeType(),
            'Content-Disposition' => ($request->boolean('inline') ? 'inline' : 'attachment').'; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    private function find(string $uuid): Invoice
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $uuid) === 1, 404);

        $model = AlfakitInvoices::invoiceModel();
        $invoice = $model::query()->where('uuid', $uuid)->first();

        // Drafts aren't shared with customers (panel previews may show them).
        abort_if($invoice === null || ($invoice->status === InvoiceStatus::Draft && ! request()->boolean('preview')), 404);

        return $invoice;
    }

    private function secure(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        return $response;
    }
}
