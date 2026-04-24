<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Center wristband batch</title>
    <style>
        @page { margin: 10px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8px; }
        .wb {
            display: inline-block;
            width: 48%;
            border: 1px solid #000;
            padding: 6px;
            margin: 2px;
            vertical-align: top;
            box-sizing: border-box;
            min-height: 80px;
        }
        .name { font-size: 12px; font-weight: bold; margin: 0 0 2px 0; }
        .meta { font-size: 8px; margin: 0; line-height: 1.2; }
        .barcode-text { font-family: 'Courier New', monospace; font-size: 8px; margin-top: 2px; }
        .qr { width: 60px; height: 60px; float: right; margin-left: 4px; }
    </style>
</head>
<body>
    <h2>Center wristband batch — {{ count($items) }} participants — {{ now()->format('Y-m-d H:i') }}</h2>
    @foreach ($items as $it)
        <div class="wb">
            <div class="qr">{!! $it['qr_svg'] !!}</div>
            <p class="name">{{ $it['participant']->last_name }}, {{ $it['participant']->first_name }}</p>
            <p class="meta">MRN: {{ $it['participant']->mrn }}</p>
            <p class="meta">DOB: {{ $it['participant']->dob?->format('m/d/Y') }}</p>
            <p class="barcode-text">{{ $it['participant']->barcode_value }}</p>
        </div>
    @endforeach
</body>
</html>
