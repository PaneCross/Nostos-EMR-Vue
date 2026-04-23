<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Wristband - {{ $participant->mrn }}</title>
    <style>
        @page { margin: 8px; }
        body { font-family: 'DejaVu Sans', Helvetica, sans-serif; font-size: 9px; margin: 0; padding: 0; color: #000; }
        .wrap { border: 1px solid #000; padding: 6px; height: 128px; box-sizing: border-box; }
        .row { display: table; width: 100%; }
        .col-left { display: table-cell; width: 65%; vertical-align: top; padding-right: 4px; }
        .col-right { display: table-cell; width: 35%; vertical-align: middle; text-align: center; }
        .name { font-size: 14px; font-weight: bold; margin: 0 0 2px 0; }
        .meta { font-size: 8px; margin: 0; line-height: 1.3; }
        .label { font-weight: bold; }
        .barcode-text { font-family: 'Courier New', monospace; font-size: 9px; letter-spacing: 1px; margin-top: 3px; word-wrap: break-word; }
        .allergy {
            border: 1px solid #c00;
            background: #fee;
            color: #900;
            font-weight: bold;
            padding: 2px 4px;
            margin-top: 4px;
            font-size: 8px;
            text-transform: uppercase;
        }
        .qr { width: 80px; height: 80px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="row">
            <div class="col-left">
                <p class="name">{{ $participant->last_name }}, {{ $participant->first_name }}</p>
                <p class="meta"><span class="label">MRN:</span> {{ $participant->mrn }}</p>
                <p class="meta"><span class="label">DOB:</span> {{ $participant->dob?->format('m/d/Y') }}
                    @if ($participant->gender)
                        &nbsp;&nbsp;<span class="label">Sex:</span> {{ strtoupper(substr($participant->gender, 0, 1)) }}
                    @endif
                </p>
                <p class="barcode-text">{{ $participant->barcode_value }}</p>

                @if ($allergies->count() > 0)
                    <div class="allergy">
                        ⚠ ALLERGIES:
                        @foreach ($allergies->take(3) as $a)
                            {{ $a->allergen_name }}@if (!$loop->last), @endif
                        @endforeach
                        @if ($allergies->count() > 3)
                            &nbsp;(+{{ $allergies->count() - 3 }} more)
                        @endif
                    </div>
                @else
                    <div class="meta" style="margin-top: 3px;"><em>No known allergies</em></div>
                @endif
            </div>
            <div class="col-right">
                <div class="qr">{!! $qr_svg !!}</div>
            </div>
        </div>
    </div>
</body>
</html>
