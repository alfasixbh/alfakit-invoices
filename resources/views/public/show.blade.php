{{-- The customer's invoice page (signed link, outside the panel). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $invoice->getTitle() }} · {{ $seller['name'] ?? $branding->name }}</title>
    @include('alfakit-invoices::partials.styles')
    <style>
        .ak-bar { max-width: 190mm; margin: 0 auto 14px; display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; justify-content: space-between; font-family: {!! $fontStack !!}; }
        .ak-bar-info { display: flex; flex-direction: column; gap: 2px; }
        .ak-bar-amount { font-size: 15pt; font-weight: bold; color: #111827; }
        .ak-bar-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .ak-button { display: inline-flex; align-items: center; gap: 6px; min-height: 40px; padding: 8px 16px; border-radius: 8px; font: inherit; font-size: 10pt; font-weight: 600; text-decoration: none; cursor: pointer; border: 1px solid #d1d5db; background: #fff; color: #111827; }
        .ak-button:hover { background: #f9fafb; }
        .ak-button:focus-visible { outline: 3px solid {{ $branding->color }}; outline-offset: 2px; }
        .ak-button-primary { background: {{ $branding->color }}; border-color: {{ $branding->color }}; color: {{ $branding->contrastColor() }}; }
        .ak-button-primary:hover { background: {{ $branding->color }}; filter: brightness(0.95); }
        .ak-button svg { width: 16px; height: 16px; flex-shrink: 0; }
        .ak-sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        @media screen and (max-width: 640px) { .ak-bar { padding: 14px 14px 0; } .ak-bar-actions, .ak-button { width: 100%; } .ak-button { justify-content: center; } }
        @media print { .ak-bar { display: none; } }
    </style>
</head>
<body>
<main>
    <h1 class="ak-sr-only">{{ $invoice->getTitle() }}</h1>

    <div class="ak-bar" role="region" aria-label="{{ __('alfakit-invoices::invoices.public.toolbar') }}">
        <div class="ak-bar-info">
            @if ($invoice->type->isPayable() && $invoice->status !== \AlfaSix\AlfakitInvoices\Enums\InvoiceStatus::Void)
                <span class="ak-muted ak-small">{{ (int) $invoice->amount_due > 0 ? __('alfakit-invoices::invoices.totals.due') : __('alfakit-invoices::invoices.public.paid_in_full') }}</span>
                <span class="ak-bar-amount">{{ $formatter->money((int) $invoice->amount_due) }}</span>
            @else
                <span class="ak-muted ak-small">{{ __('alfakit-invoices::invoices.totals.total') }}</span>
                <span class="ak-bar-amount">{{ $formatter->money((int) $invoice->total) }}</span>
            @endif
            <span role="status"><span class="ak-status ak-status-{{ $invoice->status->value }}">{{ $invoice->status->getLabel() }}</span>@if ($invoice->type->isPayable() && $invoice->due_date && (int) $invoice->amount_due > 0) <span class="ak-small ak-muted">{{ __('alfakit-invoices::invoices.public.due_on', ['date' => $formatter->date($invoice->due_date)]) }}</span>@endif</span>
        </div>
        <div class="ak-bar-actions">
            @if ($payUrl)
                <a class="ak-button ak-button-primary" href="{{ $payUrl }}" rel="noopener">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                    {{ __('alfakit-invoices::invoices.public.pay_now') }}
                </a>
            @endif
            @if ($downloadUrl)
                <a class="ak-button {{ $payUrl ? '' : 'ak-button-primary' }}" href="{{ $downloadUrl }}" download>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                    {{ $isPdf ? __('alfakit-invoices::invoices.public.download_pdf') : __('alfakit-invoices::invoices.public.download') }}
                </a>
            @endif
            <button type="button" class="ak-button" onclick="window.print()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                {{ __('alfakit-invoices::invoices.public.print') }}
            </button>
        </div>
    </div>

    @include('alfakit-invoices::partials.body')
</main>
</body>
</html>
