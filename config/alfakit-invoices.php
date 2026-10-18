<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Panel
    |--------------------------------------------------------------------------
    |
    | Work that runs outside a panel request (the customer view link, queued
    | emails, the overdue command) uses the InvoicesPlugin options and branding
    | of the panel an invoice was created in, else this panel, else the first
    | panel that registers the plugin.
    |
    */

    'panel' => null,

    /*
    |--------------------------------------------------------------------------
    | Numbering
    |--------------------------------------------------------------------------
    |
    | Each document type has its own sequence, per tenant. `format` tokens:
    | {YYYY} {YY} {MM} {DD} (the issue date) and {SEQ} or {SEQ:5} (the sequence
    | number, zero-padded to 5 digits). A sequence restarts when the date tokens
    | of the format change: yearly with {YYYY}, monthly with {MM}, never without
    | date tokens. `assign` is `create` (drafts get a number right away) or `issue`
    | (drafts show "Draft" and get a number when sent, paid or converted, so
    | deleted drafts never leave gaps).
    |
    */

    'numbering' => [
        'assign' => 'create',
        'types' => [
            'invoice' => ['prefix' => 'INV-', 'format' => '{YYYY}-{SEQ:5}'],
            'quote' => ['prefix' => 'QUO-', 'format' => '{YYYY}-{SEQ:5}'],
            'credit_note' => ['prefix' => 'CN-', 'format' => '{YYYY}-{SEQ:5}'],
            'receipt' => ['prefix' => 'RCT-', 'format' => '{YYYY}-{SEQ:5}'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Money
    |--------------------------------------------------------------------------
    |
    | Amounts are integers of the currency's minor unit (cents, fils, yen).
    | `rounding` is `per_line` (each line's tax is rounded, the default) or
    | `per_total` (taxes are summed exactly per rate and rounded once). The
    | currency of new documents defaults to the panel's currency.
    |
    */

    'rounding' => 'per_line',

    'currency' => null,

    'quantity_decimals' => 4,

    'due_days' => 30,

    'quote_valid_days' => 30,

    'payment_methods' => ['bank_transfer', 'card', 'cash', 'cheque', 'online', 'other'],

    /*
    |--------------------------------------------------------------------------
    | Seller
    |--------------------------------------------------------------------------
    |
    | Printed on every document. `InvoicesPlugin::seller()` overrides it per
    | tenant; the panel's brand name is used when `name` is null.
    |
    */

    'seller' => [
        'name' => null,
        'address' => null,
        'email' => null,
        'phone' => null,
        'tax_id' => null,
        'website' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Documents (PDF)
    |--------------------------------------------------------------------------
    |
    | `renderer` is a class implementing PdfRenderer, or null to pick the first
    | installed driver: spatie/laravel-pdf, barryvdh/laravel-dompdf, then
    | dompdf/dompdf. Without one, documents are print-ready HTML files.
    |
    */

    'pdf' => [
        'renderer' => null,
        'paper' => 'a4',
        'view' => 'alfakit-invoices::document',
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer view link
    |--------------------------------------------------------------------------
    |
    | Customers open their invoice at a signed URL outside the panel (no
    | account needed). Opening it marks a sent invoice as viewed. Links never
    | expire unless `link_expires_days` is set. Previews and downloads from the
    | panel use short-lived links (`preview_minutes`) that don't count as views.
    |
    */

    'route' => [
        'enabled' => true,
        'prefix' => 'invoices',
        'middleware' => ['web', 'throttle:60,1'],
        'name' => 'alfakit-invoices.',
        'domain' => null,
    ],

    'link_expires_days' => null,

    'preview_minutes' => 10,

    /*
    |--------------------------------------------------------------------------
    | Emails
    |--------------------------------------------------------------------------
    */

    'mail' => [
        'connection' => null,
        'queue' => null,
        'attach_document' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Overdue invoices
    |--------------------------------------------------------------------------
    |
    | `php artisan alfakit-invoices:mark-overdue` marks unpaid invoices past their
    | due date as overdue. With `schedule` on, it is scheduled daily at `time`.
    |
    */

    'overdue' => [
        'schedule' => true,
        'time' => '00:30',
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    */

    'models' => [
        'invoice' => AlfaSix\AlfakitInvoices\Models\Invoice::class,
        'item' => AlfaSix\AlfakitInvoices\Models\InvoiceItem::class,
        'payment' => AlfaSix\AlfakitInvoices\Models\Payment::class,
        'tax' => AlfaSix\AlfakitInvoices\Models\Tax::class,
        'sequence' => AlfaSix\AlfakitInvoices\Models\NumberSequence::class,
    ],

];
