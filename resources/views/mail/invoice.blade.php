<x-mail::message>
# {{ $invoice->getTitle() }}

@if ($body)
{{ $body }}
@else
{{ __('alfakit-invoices::invoices.mail.greeting', ['name' => $invoice->customer_name ?? '']) }}

{{ __('alfakit-invoices::invoices.mail.intro_'.$invoice->type->value, ['number' => $invoice->getDisplayNumber()]) }}
@endif

<x-mail::table>
| | |
|:--|--:|
| {{ __('alfakit-invoices::invoices.totals.total') }} | **{{ $formatter->money((int) $invoice->total) }}** |
@if ($invoice->type->isPayable() && (int) $invoice->amount_paid > 0)
| {{ __('alfakit-invoices::invoices.totals.due') }} | **{{ $formatter->money((int) $invoice->amount_due) }}** |
@endif
@if ($invoice->due_date)
| {{ $invoice->type === \AlfaSix\AlfakitInvoices\Enums\InvoiceType::Quote ? __('alfakit-invoices::invoices.document.valid_until') : __('alfakit-invoices::invoices.document.due_date') }} | {{ $formatter->date($invoice->due_date) }} |
@endif
</x-mail::table>

@if ($url)
<x-mail::button :url="$url">
{{ __('alfakit-invoices::invoices.mail.view', ['type' => $invoice->type->getLabel()]) }}
</x-mail::button>
@endif

{{ __('alfakit-invoices::invoices.mail.outro') }}
@if ($poweredBy ?? null)

<x-mail::subcopy>
{{ $poweredBy }}
</x-mail::subcopy>
@endif
</x-mail::message>
