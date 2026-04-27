{{-- Annual QAPI Evaluation artifact per 42 CFR §460.200 --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Annual QAPI Evaluation: {{ $year }}</title>
    <style>
        @page { margin: 0.75in; }
        body { font-family: DejaVu Sans, Helvetica, sans-serif; font-size: 10.5pt; color: #111; line-height: 1.45; }
        h1 { font-size: 18pt; margin: 0 0 0.25em; }
        h2 { font-size: 13pt; margin: 1.1em 0 0.25em; border-bottom: 1px solid #888; padding-bottom: 2px; }
        h3 { font-size: 11pt; margin: 0.75em 0 0.2em; }
        p { margin: 0.35em 0; }
        .meta { font-size: 9.5pt; color: #555; }
        table.kpi { width: 100%; border-collapse: collapse; margin-top: 0.5em; }
        table.kpi td { padding: 6px 8px; border: 1px solid #ccc; }
        table.kpi td.label { background: #f2f2f2; width: 55%; }
        table.projects { width: 100%; border-collapse: collapse; margin-top: 0.5em; font-size: 9.5pt; }
        table.projects th, table.projects td { border: 1px solid #ccc; padding: 4px 6px; vertical-align: top; text-align: left; }
        table.projects th { background: #f2f2f2; }
        .status-completed   { color: #267a3c; font-weight: 600; }
        .status-active      { color: #1e6bc6; font-weight: 600; }
        .status-remeasuring { color: #a06500; font-weight: 600; }
        .status-planning    { color: #555; font-weight: 600; }
        .status-suspended   { color: #a01a1a; font-weight: 600; }
        .footer { margin-top: 2em; font-size: 9pt; color: #666; border-top: 1px solid #ccc; padding-top: 0.5em; }
        .sig-block { margin-top: 1.5em; padding: 0.5em 0.75em; border: 1px dashed #888; background: #fafafa; }
    </style>
</head>
<body>
    <h1>Annual QAPI Evaluation: {{ $year }}</h1>
    <p class="meta">
        {{ $tenantName }} · Generated {{ $generatedAt->format('F j, Y g:i A') }}
        by {{ optional($generatedBy)->first_name }} {{ optional($generatedBy)->last_name }}
    </p>
    <p class="meta">42 CFR §460.130–§460.140 and §460.200: Quality Assessment and Performance Improvement Program</p>

    <h2>Executive Summary</h2>
    <table class="kpi">
        <tr><td class="label">Total projects in {{ $year }}</td><td>{{ $summary['total_projects'] ?? 0 }}</td></tr>
        <tr><td class="label">Active projects at year end</td><td>{{ $summary['active_count'] ?? 0 }}</td></tr>
        <tr><td class="label">Completed projects</td><td>{{ $summary['completed_count'] ?? 0 }}</td></tr>
        <tr><td class="label">Projects meeting CMS minimum (≥ {{ $minRequired }} active)</td>
            <td>{{ ($summary['active_count'] ?? 0) >= $minRequired ? 'YES' : 'NO' }}</td></tr>
        <tr><td class="label">Total incidents reported</td><td>{{ $summary['incident_count'] ?? 0 }}</td></tr>
        <tr><td class="label">Total grievances received</td><td>{{ $summary['grievance_count'] ?? 0 }}</td></tr>
        <tr><td class="label">Total appeals filed</td><td>{{ $summary['appeal_count'] ?? 0 }}</td></tr>
        <tr><td class="label">Mortality events (deaths)</td><td>{{ $summary['mortality_count'] ?? 0 }}</td></tr>
    </table>

    <h2>QAPI Projects: Detailed</h2>
    @if (empty($projects))
        <p class="meta">No QAPI projects on record for {{ $year }}.</p>
    @else
        <table class="projects">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Domain</th>
                    <th>Status</th>
                    <th>Aim / Target</th>
                    <th>Baseline → Current → Target</th>
                    <th>Findings</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($projects as $p)
                    <tr>
                        <td>{{ $p['title'] }}</td>
                        <td>{{ $p['domain_label'] ?? $p['domain'] ?? '-' }}</td>
                        <td class="status-{{ $p['status'] ?? 'planning' }}">{{ ucfirst($p['status'] ?? '-') }}</td>
                        <td>{{ $p['aim_statement'] ?? '-' }}</td>
                        <td>
                            {{ $p['baseline_metric'] ?? '-' }} →
                            {{ $p['current_metric'] ?? '-' }} →
                            {{ $p['target_metric'] ?? '-' }}
                        </td>
                        <td>{{ $p['findings'] ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Quality Indicators (Level I / Level II touch-points)</h2>
    <p class="meta">
        The following indicators were captured during {{ $year }}. Full Level I/II submissions are generated
        quarterly; this annual summary is a governing-body readout. (Detailed calculations in Level II
        reporting export, once available.)
    </p>
    <ul>
        <li>Incidents by type: {{ $summary['incident_types_summary'] ?? '-' }}</li>
        <li>Appeals overturned: {{ $summary['appeals_overturned'] ?? 0 }} of {{ $summary['appeal_count'] ?? 0 }}</li>
        <li>Grievance avg. resolution time: {{ $summary['grievance_avg_resolution_days'] ?? '-' }} days</li>
    </ul>

    <h2>Governing Body Review</h2>
    <div class="sig-block">
        <p><strong>Date reviewed:</strong> _______________________</p>
        <p><strong>Reviewer (name, role):</strong> _______________________</p>
        <p><strong>Signature:</strong> _______________________</p>
        <p><strong>Action/notes:</strong></p>
        <p style="min-height: 4em;">&nbsp;</p>
    </div>

    <div class="footer">
        Retained as part of tenant compliance records per 42 CFR §460.210. Document ID {{ $documentId ?? 'pending' }}.
    </div>
</body>
</html>
