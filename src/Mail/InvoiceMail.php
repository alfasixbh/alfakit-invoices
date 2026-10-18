<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Mail;

use AlfaSix\Alfakit\Mail\Concerns\UsesAlfakitMailTheme;
use AlfaSix\Alfakit\Mail\Concerns\UsesEditableTemplate;
use AlfaSix\Alfakit\Mail\EditableTemplates;
use AlfaSix\AlfakitInvoices\AlfakitInvoices;
use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use AlfaSix\AlfakitInvoices\Support\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A document emailed to the customer (queued): a short message, the amount and due date,
 * a button to the signed invoice page and the document attached (PDF, or HTML without a
 * PDF driver). Sent in the document's locale, in the AlfaKit mail theme. Admins can edit
 * the default subject and message (`invoices.document`); a subject or message typed when
 * sending wins.
 */
class InvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;
    use UsesAlfakitMailTheme;
    use UsesEditableTemplate;

    public const TEMPLATE = 'invoices.document';

    public function __construct(
        public Invoice $invoice,
        public ?string $subjectLine = null,
        public ?string $body = null,
        public bool $attachDocument = true,
    ) {
        $this->onConnection(is_string($connection = config('alfakit-invoices.mail.connection')) ? $connection : null);
        $this->onQueue(is_string($queue = config('alfakit-invoices.mail.queue')) ? $queue : null);
        $this->afterCommit();

        if (is_string($invoice->locale) && $invoice->locale !== '') {
            $this->locale($invoice->locale);
        }
    }

    public function envelope(): Envelope
    {
        $seller = AlfakitInvoices::plugin($this->invoice)->getSeller($this->invoice->getTenant());
        $name = $seller['name'] ?? AlfakitInvoices::plugin($this->invoice)->getBranding($this->invoice)->name;

        return new Envelope(
            subject: $this->subjectLine !== null && trim($this->subjectLine) !== ''
                ? $this->subjectLine
                : $this->templateSubject((string) __('alfakit-invoices::invoices.mail.subject', ['type' => $this->invoice->type->getLabel(), 'number' => $this->invoice->getDisplayNumber(), 'name' => $name])),
        );
    }

    public function content(): Content
    {
        $poweredBy = InvoicesPlugin::get($this->invoice)->getPoweredByEmailLine();
        $content = new Content(markdown: AlfakitInvoices::view('mail.invoice'), with: [
            'invoice' => $this->invoice,
            'url' => $this->invoice->getCustomerUrl(),
            'body' => $this->body,
            'formatter' => $this->invoice->formatter(),
            'poweredBy' => $poweredBy,
        ]);

        // A message typed when sending replaces the edited template.
        return $this->body !== null && trim($this->body) !== '' ? $content : $this->templateContent($content, $poweredBy);
    }

    public function templateKey(): string
    {
        return self::TEMPLATE;
    }

    public function templateData(): array
    {
        $formatter = $this->invoice->formatter();
        $url = $this->invoice->getCustomerUrl();
        $seller = AlfakitInvoices::plugin($this->invoice)->getSeller($this->invoice->getTenant());

        return [
            'type' => $this->invoice->type->getLabel(),
            'number' => $this->invoice->getDisplayNumber(),
            'customer' => (string) ($this->invoice->customer_name ?? ''),
            'total' => $formatter->money((int) $this->invoice->total),
            'amount_due' => $formatter->money((int) $this->invoice->amount_due),
            'due_date' => $this->invoice->due_date === null ? '' : $formatter->date($this->invoice->due_date),
            'seller' => (string) ($seller['name'] ?? AlfakitInvoices::plugin($this->invoice)->getBranding($this->invoice)->name),
            'url' => (string) $url,
            'button' => $url === null ? '' : EditableTemplates::button($url, (string) __('alfakit-invoices::invoices.mail.view', ['type' => $this->invoice->type->getLabel()])),
        ];
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->attachDocument || ! (bool) config('alfakit-invoices.mail.attach_document', true)) {
            return [];
        }

        $document = Document::for($this->invoice);

        return [
            Attachment::fromData(static fn (): string => $document->render(), $document->filename())->withMime($document->mimeType()),
        ];
    }
}
