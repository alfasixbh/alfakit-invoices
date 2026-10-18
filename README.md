# AlfaKit Invoices

<p><picture><source media="(prefers-color-scheme: dark)" srcset="../../../art/alfasix/alfa-six-micro-white.svg"><img src="../../../art/alfasix/alfa-six-micro-navy.svg" alt="" width="24" height="18" align="top"></picture> <sub>by <a href="https://alfasixbh.com">Alfa Six</a></sub></p>

Invoices, quotes, credit notes and receipts for [AlfaKit](https://alfakit.dev) panels. Features:

- Exact integer money in any currency (JPY, BHD and the rest).
- Inclusive, exclusive and compound taxes, rounded per line or on the total.
- Atomic numbering per type and tenant (`INV-{YYYY}-{SEQ:5}`).
- Totals recomputed on the server as you edit.
- Payments, overdue tracking and receivables widgets.
- A branded, right-to-left-ready document with a pluggable PDF renderer.
- A signed customer link with an optional "Pay now" hook.

## Installation

```bash
composer require alfasix/alfakit-invoices
php artisan vendor:publish --tag=alfakit-invoices-migrations
php artisan migrate
```

Without a PDF library, documents are print-ready HTML files. Install `spatie/laravel-pdf` or `barryvdh/laravel-dompdf` to get PDF files; the plugin picks the installed one.

## Register

```php
use AlfaSix\AlfakitInvoices\InvoicesPlugin;

$panel->plugin(
    InvoicesPlugin::make()
        ->authorize(fn (User $user): bool => $user->can_bill)
        ->customers(fn (?Team $tenant) => Customer::query()->whereBelongsTo($tenant), titleAttribute: 'company')
        ->seller(['name' => 'Acme W.L.L.', 'tax_id' => 'VAT-123']),
);
```

```php
$invoice = Invoice::createWithItems(['billable' => $customer, 'currency' => 'BHD'], [
    ['description' => 'Design', 'quantity' => '10', 'unit_price' => 25000, 'tax_ids' => [$vat->id]],
]);

$invoice->markAsSent();
$invoice->recordPayment(100000, method: 'bank_transfer');
```

`php artisan alfakit-invoices:mark-overdue` runs daily through the scheduler.

Read the full documentation at [alfakit.dev/docs/plugins/invoices](https://alfakit.dev/docs/plugins/invoices).

## License

MIT. See [LICENSE.md](LICENSE.md).
