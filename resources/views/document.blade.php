{{-- The complete document file: what PdfRenderer receives and customers download. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice->getTitle() }}</title>
    @include('alfakit-invoices::partials.styles')
</head>
<body>
@include('alfakit-invoices::partials.body')
</body>
</html>
