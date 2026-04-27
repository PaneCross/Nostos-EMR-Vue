<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Day Center Attendance Roster</title>
    <style>
        @page { margin: 0.5in; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #111; }
        h1 { font-size: 14pt; margin: 0; }
        .meta { font-size: 9pt; color: #555; margin-bottom: 12pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 6pt; }
        th, td { border: 1px solid #999; padding: 4pt 6pt; text-align: left; vertical-align: top; }
        th { background: #eee; font-size: 9pt; }
        td.check { width: 24pt; text-align: center; }
        .footer { margin-top: 18pt; font-size: 8pt; color: #666; }
    </style>
</head>
<body>
    <h1>Day Center Roster: {{ $site_name }}</h1>
    <div class="meta">
        {{ $tenant_name }} · {{ \Carbon\Carbon::parse($date)->format('l, F j, Y') }}<br>
        Generated {{ $generated_at->format('M j, Y g:i A T') }}
    </div>

    <table>
        <thead>
            <tr>
                <th>MRN</th>
                <th>Name</th>
                <th>Source</th>
                <th>Status</th>
                <th class="check">In</th>
                <th class="check">Out</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['mrn'] }}</td>
                    <td>{{ $row['name'] }}@if (!empty($row['preferred_name'])) <em>({{ $row['preferred_name'] }})</em>@endif</td>
                    <td>{{ str_replace('_', ' ', $row['source']) }}</td>
                    <td>{{ $row['attendance'] ?? '-' }}</td>
                    <td class="check">☐</td>
                    <td class="check">☐</td>
                    <td>&nbsp;</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="footer">Roster includes home-site participants on this weekday's recurring schedule plus any cross-site appointments arriving here. Manual check-marks captured here should be entered into NostosEMR before end-of-day.</p>
</body>
</html>
