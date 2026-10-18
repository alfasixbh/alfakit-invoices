{{--
    The printable invoice document. Dompdf-compatible on purpose (tables, no flexbox or
    grid, inline CSS) and print-ready in browsers. `$start` / `$end` are the physical
    sides for the document's direction, so the layout mirrors in right-to-left locales.
--}}
@php
    /** @var \AlfaSix\AlfakitInvoices\Models\Invoice $invoice */
    /** @var \AlfaSix\AlfakitInvoices\Support\Formatter $formatter */
    /** @var \AlfaSix\AlfakitInvoices\Support\Branding $branding */
    $type = $invoice->type;
    $isPayable = $type->isPayable();
    $taxes = is_array($invoice->tax_breakdown) ? $invoice->tax_breakdown : [];
    $hasDiscounts = $items->contains(fn ($item) => (int) $item->discount_amount !== 0);
    $hasTaxes = $items->contains(fn ($item) => (int) $item->tax_amount !== 0);
    $customer = array_filter([$invoice->customer_name, $invoice->customer_address, $invoice->customer_email, $invoice->customer_phone]);
@endphp
<div class="ak-doc" id="alfakit-invoice-document">
    @if ($invoice->status === \AlfaSix\AlfakitInvoices\Enums\InvoiceStatus::Void)
        <div class="ak-watermark" aria-hidden="true">{{ __('alfakit-invoices::invoices.statuses.void') }}</div>
    @endif

    <div class="ak-accent-bar"></div>

    <table role="presentation">
        <tr>
            <td style="width: 55%;">
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $seller['name'] ?? $branding->name }}" class="ak-logo">
                @else
                    <div class="ak-brand">{{ $seller['name'] ?? $branding->name }}</div>
                @endif
                <div class="ak-small ak-muted" style="padding-top: 6px;">
                    @if ($logo)<strong style="color: #111827;">{{ $seller['name'] ?? $branding->name }}</strong><br>@endif
                    @if ($seller['address']){!! nl2br(e($seller['address'])) !!}<br>@endif
                    @if ($seller['email']){{ $seller['email'] }}<br>@endif
                    @if ($seller['phone']){{ $seller['phone'] }}<br>@endif
                    @if ($seller['website']){{ $seller['website'] }}<br>@endif
                    @if ($seller['tax_id']){{ __('alfakit-invoices::invoices.document.tax_id') }}: {{ $seller['tax_id'] }}@endif
                </div>
            </td>
            <td style="width: 45%;">
                <div class="ak-title">{{ $type->getLabel() }}</div>
                <div class="ak-number">{{ $invoice->getDisplayNumber() }}</div>
                <div class="ak-end" style="padding-top: 6px;">
                    <span class="ak-status ak-status-{{ $invoice->status->value }}">{{ $invoice->status->getLabel() }}</span>
                </div>
            </td>
        </tr>
    </table>

    <table role="presentation" class="ak-block">
        <tr>
            <td style="width: 55%; padding-{{ $end }}: 16px;">
                <div class="ak-label">{{ $type === \AlfaSix\AlfakitInvoices\Enums\InvoiceType::Quote ? __('alfakit-invoices::invoices.document.prepared_for') : __('alfakit-invoices::invoices.document.bill_to') }}</div>
                @if ($customer === [])
                    <div class="ak-muted">{{ __('alfakit-invoices::invoices.labels.no_customer') }}</div>
                @else
                    @if ($invoice->customer_name)<div style="font-weight: bold; color: #111827;">{{ $invoice->customer_name }}</div>@endif
                    <div class="ak-small">
                        @if ($invoice->customer_address){!! nl2br(e($invoice->customer_address)) !!}<br>@endif
                        @if ($invoice->customer_email){{ $invoice->customer_email }}<br>@endif
                        @if ($invoice->customer_phone){{ $invoice->customer_phone }}<br>@endif
                        @if ($invoice->customer_tax_id){{ __('alfakit-invoices::invoices.document.tax_id') }}: {{ $invoice->customer_tax_id }}@endif
                    </div>
                @endif
            </td>
            <td style="width: 45%;">
                <table role="presentation" class="ak-meta">
                    <tr>
                        <td class="ak-meta-label">{{ __('alfakit-invoices::invoices.document.issue_date') }}</td>
                        <td class="ak-num">{{ $formatter->date($invoice->issue_date) }}</td>
                    </tr>
                    @if ($invoice->due_date)
                        <tr>
                            <td class="ak-meta-label">{{ $type === \AlfaSix\AlfakitInvoices\Enums\InvoiceType::Quote ? __('alfakit-invoices::invoices.document.valid_until') : __('alfakit-invoices::invoices.document.due_date') }}</td>
                            <td class="ak-num">{{ $formatter->date($invoice->due_date) }}</td>
                        </tr>
                    @endif
                    @if ($invoice->reference)
                        <tr>
                            <td class="ak-meta-label">{{ __('alfakit-invoices::invoices.document.reference') }}</td>
                            <td class="ak-num">{{ $invoice->reference }}</td>
                        </tr>
                    @endif
                    @if ($invoice->source && $invoice->source->number)
                        <tr>
                            <td class="ak-meta-label">{{ $type === \AlfaSix\AlfakitInvoices\Enums\InvoiceType::CreditNote ? __('alfakit-invoices::invoices.document.credits') : __('alfakit-invoices::invoices.document.from_quote') }}</td>
                            <td class="ak-num">{{ $invoice->source->number }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td class="ak-meta-label">{{ __('alfakit-invoices::invoices.document.currency') }}</td>
                        <td class="ak-num">{{ $invoice->currency }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="ak-items">
        <thead>
            <tr>
                <th scope="col">{{ __('alfakit-invoices::invoices.fields.description') }}</th>
                <th scope="col" class="ak-num">{{ __('alfakit-invoices::invoices.fields.quantity') }}</th>
                <th scope="col" class="ak-num">{{ __('alfakit-invoices::invoices.fields.unit_price') }}</th>
                @if ($hasDiscounts)<th scope="col" class="ak-num ak-hide-sm">{{ __('alfakit-invoices::invoices.fields.discount') }}</th>@endif
                @if ($hasTaxes)<th scope="col" class="ak-num ak-hide-sm">{{ __('alfakit-invoices::invoices.fields.tax') }}</th>@endif
                <th scope="col" class="ak-num">{{ __('alfakit-invoices::invoices.fields.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $item)
                <tr>
                    <td style="white-space: pre-line;">{{ $item->description }}@if ($item->taxRates() !== [])<div class="ak-small ak-muted">{{ collect($item->taxRates())->map(fn ($tax) => $tax->name.' '.$formatter->rate($tax->rate).($tax->inclusive ? ' ('.__('alfakit-invoices::invoices.taxes.inclusive_short').')' : ''))->implode(', ') }}</div>@endif</td>
                    <td class="ak-num">{{ $formatter->quantity($item->quantity) }}</td>
                    <td class="ak-num">{{ $formatter->money((int) $item->unit_price) }}</td>
                    @if ($hasDiscounts)<td class="ak-num ak-hide-sm">{{ (int) $item->discount_amount === 0 ? '—' : '−'.$formatter->money((int) $item->discount_amount) }}</td>@endif
                    @if ($hasTaxes)<td class="ak-num ak-hide-sm">{{ $formatter->money((int) $item->tax_amount) }}</td>@endif
                    <td class="ak-num">{{ $formatter->money((int) $item->total) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="ak-muted">{{ __('alfakit-invoices::invoices.labels.no_items') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <table role="presentation" class="ak-totals">
        <tr>
            <td style="width: 50%;"></td>
            <td style="width: 50%; padding: 0;">
                <table>
                    <tr>
                        <td>{{ __('alfakit-invoices::invoices.totals.subtotal') }}</td>
                        <td class="ak-num">{{ $formatter->money((int) $invoice->subtotal) }}</td>
                    </tr>
                    @if ((int) $invoice->discount_total !== 0)
                        <tr>
                            <td>{{ __('alfakit-invoices::invoices.totals.discount') }}</td>
                            <td class="ak-num">−{{ $formatter->money((int) $invoice->discount_total) }}</td>
                        </tr>
                    @endif
                    @foreach ($taxes as $tax)
                        <tr>
                            <td>{{ $tax['name'] ?? '' }} {{ $formatter->rate((string) ($tax['rate'] ?? '0')) }}@if (! empty($tax['inclusive'])) <span class="ak-muted ak-small">({{ __('alfakit-invoices::invoices.taxes.included') }})</span>@endif</td>
                            <td class="ak-num">{{ $formatter->money((int) ($tax['amount'] ?? 0)) }}</td>
                        </tr>
                    @endforeach
                    <tr class="ak-grand">
                        <td>{{ __('alfakit-invoices::invoices.totals.total') }}</td>
                        <td class="ak-num">{{ $formatter->money((int) $invoice->total) }}</td>
                    </tr>
                    @if ($isPayable && (int) $invoice->amount_paid > 0)
                        <tr>
                            <td>{{ __('alfakit-invoices::invoices.totals.paid') }}</td>
                            <td class="ak-num">−{{ $formatter->money((int) $invoice->amount_paid) }}</td>
                        </tr>
                    @endif
                    @if ($isPayable && $invoice->status !== \AlfaSix\AlfakitInvoices\Enums\InvoiceStatus::Void)
                        <tr class="ak-due">
                            <td>{{ __('alfakit-invoices::invoices.totals.due') }}</td>
                            <td class="ak-num">{{ $formatter->money((int) $invoice->amount_due) }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @if ($payments->isNotEmpty())
        <div class="ak-block">
            <div class="ak-label">{{ __('alfakit-invoices::invoices.document.payments') }}</div>
            <table class="ak-meta">
                @foreach ($payments as $payment)
                    <tr>
                        <td class="ak-meta-label">{{ $formatter->date($payment->paid_at) }}</td>
                        <td>{{ $payment->getMethodLabel() }}@if ($payment->reference) · {{ $payment->reference }}@endif</td>
                        <td class="ak-num">{{ $formatter->money((int) $payment->amount) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if ($invoice->notes || $invoice->terms)
        <div class="ak-notes">
            @if ($invoice->notes)
                <div class="ak-label">{{ __('alfakit-invoices::invoices.fields.notes') }}</div>
                <p>{{ $invoice->notes }}</p>
            @endif
            @if ($invoice->terms)
                <div class="ak-label">{{ __('alfakit-invoices::invoices.fields.terms') }}</div>
                <p>{{ $invoice->terms }}</p>
            @endif
        </div>
    @endif

    <div class="ak-footer">{{ __('alfakit-invoices::invoices.document.footer', ['name' => $seller['name'] ?? $branding->name]) }}</div>
    @if ($poweredBy ?? null)
        <div class="ak-muted">{!! $poweredBy !!}</div>
    @endif
</div>
