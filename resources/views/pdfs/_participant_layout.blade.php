<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>@yield('title'): {{ $participant->first_name }} {{ $participant->last_name }}</title>
<style>
    @page { margin: 0.6in 0.6in 0.9in 0.6in; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #0f172a; margin: 0; padding: 0; }
    h1 { font-size: 16pt; margin: 0 0 2pt 0; }
    h2 { font-size: 11.5pt; margin: 12pt 0 4pt 0; padding-bottom: 2pt; border-bottom: 1px solid #cbd5e1; color: #1e293b; }
    .sub { font-size: 9pt; color: #475569; margin-bottom: 10pt; }
    .demographics { width: 100%; border-collapse: collapse; margin: 4pt 0 6pt 0; }
    .demographics td { padding: 2pt 6pt; vertical-align: top; border: 1px solid #e2e8f0; font-size: 9.5pt; }
    .demographics td.k { background: #f8fafc; font-weight: bold; color: #334155; width: 22%; }
    table.data { width: 100%; border-collapse: collapse; margin-top: 4pt; font-size: 9.5pt; }
    table.data th, table.data td { border: 1px solid #cbd5e1; padding: 3pt 5pt; text-align: left; vertical-align: top; }
    table.data th { background: #f1f5f9; font-weight: bold; }
    .empty { font-style: italic; color: #64748b; padding: 6pt; }
    .footer {
        position: fixed; bottom: 0.25in; left: 0.6in; right: 0.6in;
        text-align: center; font-size: 8pt; color: #64748b;
        border-top: 1px solid #e2e8f0; padding-top: 4pt;
    }
    .meta-row { display: flex; justify-content: space-between; font-size: 9pt; color: #475569; margin-top: 2pt; }
    .badge { display: inline-block; padding: 1pt 6pt; border-radius: 3pt; font-size: 8.5pt; }
    .badge-amber { background: #fef3c7; color: #78350f; }
    .badge-red { background: #fee2e2; color: #991b1b; }
    .badge-gray { background: #f1f5f9; color: #475569; }
</style>
</head>
<body>
<h1>@yield('title')</h1>
<div class="sub">
    {{ $participant->first_name }} {{ $participant->last_name }}
    &nbsp;·&nbsp; MRN: {{ $participant->mrn ?? '-' }}
    &nbsp;·&nbsp; DOB: {{ optional($participant->dob)->format('Y-m-d') ?: '-' }}
    &nbsp;·&nbsp; Generated {{ $generated_at->format('Y-m-d H:i') }}
</div>

<table class="demographics">
    <tr><td class="k">Enrollment</td><td>{{ ucfirst(str_replace('_', ' ', (string) $participant->enrollment_status)) }}</td>
        <td class="k">Site</td><td>{{ $participant->site?->name ?? '-' }}</td></tr>
    <tr><td class="k">Gender</td><td>{{ ucfirst((string)($participant->gender ?? '-')) }}</td>
        <td class="k">Language</td><td>{{ $participant->primary_language ?? '-' }}</td></tr>
    <tr><td class="k">Medicare ID</td><td>{{ $participant->medicare_id ?? '-' }}</td>
        <td class="k">Medicaid ID</td><td>{{ $participant->medicaid_id ?? '-' }}</td></tr>
</table>

@yield('content')

<div class="footer">
    NostosEMR · Protected Health Information (PHI): HIPAA safeguards apply · Page @yield('page', '1')
</div>
</body>
</html>
