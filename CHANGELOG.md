# Changelog

All notable changes to `alfasix/alfakit-invoices` are documented in this file.

## Unreleased

- Initial release.
- Models for invoices, quotes, credit notes and receipts: `Invoice`, `InvoiceItem`, `Tax`, `Payment` and `NumberSequence`, with a publishable migration.
- Exact integer money with `Calculator` (no floats): inclusive, exclusive and compound taxes, percentage and fixed discounts, and rounding per line or per total for any currency.
- Atomic numbering per type and tenant with configurable formats.
- `InvoiceResource`: tabs per type, a table repeater with live server-side totals, and a document page with these actions:
  - send (queued `InvoiceMail` with the document attached and a signed link)
  - record payment and mark as paid
  - duplicate, convert a quote to an invoice, issue a credit note, and void
- `TaxResource` for tax rates.
- `InvoiceStatsWidget` and `InvoiceAgingWidget`.
- `alfakit-invoices:mark-overdue`, scheduled daily.
- `PdfRenderer` contract with `SpatiePdfRenderer`, `DompdfRenderer` and an `HtmlRenderer` fallback.
- A branded document that works right to left and is formatted for the document's locale.
- A signed customer page that marks documents as viewed, with a "Pay now" hook (`payNowUsing()`, `PaymentLinkProvider`, AlfaKit Billing when installed).
- Policies and tenancy isolation.
- Translations in 13 languages.
