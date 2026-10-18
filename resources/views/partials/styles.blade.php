{{-- Styles of the document (shared by the file and the customer page). --}}
<style>
    @page { size: {{ $paper }}; margin: 18mm 16mm; }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body { font-family: {!! $fontStack !!}; font-size: 10pt; line-height: 1.45; color: #1f2937; background: #ffffff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .ak-doc { direction: {{ $direction }}; text-align: {{ $start }}; max-width: 190mm; margin: 0 auto; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; padding: 0; text-align: {{ $start }}; }
    .ak-accent-bar { height: 4px; background: {{ $branding->color }}; margin-bottom: 18px; }
    .ak-brand { font-size: 15pt; font-weight: bold; color: #111827; }
    .ak-logo { max-height: 48px; max-width: 200px; }
    .ak-muted { color: #6b7280; }
    .ak-small { font-size: 8.5pt; }
    .ak-title { font-size: 20pt; font-weight: bold; color: {{ $branding->color }}; letter-spacing: 0.02em; text-align: {{ $end }}; }
    .ak-number { font-size: 11pt; font-weight: bold; text-align: {{ $end }}; }
    .ak-end { text-align: {{ $end }}; }
    .ak-label { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.06em; color: #6b7280; padding-bottom: 3px; }
    .ak-block { padding-top: 22px; }
    .ak-meta td { padding: 2px 0; }
    .ak-meta td.ak-meta-label { color: #6b7280; padding-{{ $end }}: 12px; white-space: nowrap; }
    .ak-items { margin-top: 22px; }
    .ak-items th { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.05em; color: {{ $branding->contrastColor() }}; background: {{ $branding->color }}; padding: 7px 8px; font-weight: bold; }
    .ak-items td { padding: 8px; border-bottom: 1px solid #e5e7eb; }
    .ak-items tr:nth-child(even) td { background: #f9fafb; }
    .ak-num { text-align: {{ $end }} !important; white-space: nowrap; }
    .ak-totals { margin-top: 14px; }
    .ak-totals td { padding: 4px 8px; }
    .ak-totals .ak-grand td { font-size: 12pt; font-weight: bold; border-top: 2px solid {{ $branding->color }}; padding-top: 8px; }
    .ak-due td { font-weight: bold; color: {{ $branding->color }}; }
    .ak-status { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; border: 1px solid #d1d5db; color: #374151; }
    .ak-status-paid { border-color: #16a34a; color: #15803d; }
    .ak-status-overdue { border-color: #dc2626; color: #b91c1c; }
    .ak-status-void { border-color: #6b7280; color: #6b7280; text-decoration: line-through; }
    .ak-notes { margin-top: 26px; padding-top: 12px; border-top: 1px solid #e5e7eb; }
    .ak-notes p { margin: 0 0 10px; white-space: pre-line; }
    .ak-footer { margin-top: 30px; font-size: 8pt; color: #9ca3af; text-align: center; }
    .ak-watermark { position: fixed; top: 40%; {{ $start }}: 0; width: 100%; text-align: center; font-size: 72pt; font-weight: bold; color: rgba(220, 38, 38, 0.12); transform: rotate(-24deg); }
    @media screen { body { background: #f3f4f6; padding: 24px 12px; } .ak-doc { background: #fff; padding: 28px 28px 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08); } }
    @media screen and (max-width: 640px) { body { padding: 0; } .ak-doc { padding: 18px 14px; border-radius: 0; } .ak-items th, .ak-items td { padding: 6px 4px; } .ak-hide-sm { display: none; } }
    @media print { body { background: #fff; padding: 0; } .ak-doc { box-shadow: none; padding: 0; max-width: none; } }
</style>
