{{-- Appeal Decision Letter: 3 variants via $outcome: upheld | overturned | partially_overturned --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Appeal Decision</title>
    <style>
        @page { margin: 0.75in; }
        body { font-family: DejaVu Sans, Helvetica, sans-serif; font-size: 11pt; color: #111; line-height: 1.4; }
        h1 { font-size: 16pt; margin: 0 0 0.25em; }
        h2 { font-size: 12pt; margin: 1em 0 0.25em; border-bottom: 1px solid #888; padding-bottom: 2px; }
        .box { border: 1px solid #888; padding: 0.75em; margin: 1em 0; background: #f7f7f7; }
        .outcome-upheld { background: #fff7e6; border-color: #c48400; }
        .outcome-overturned { background: #e6f7ea; border-color: #3d8a52; }
        .outcome-partial { background: #eef3ff; border-color: #4b68c0; }
        table.meta-table { width: 100%; font-size: 10pt; margin-bottom: 1em; }
        table.meta-table td { padding: 2px 6px; vertical-align: top; }
        table.meta-table td.label { color: #555; width: 32%; }
        .footer { margin-top: 2em; font-size: 9pt; color: #666; border-top: 1px solid #ccc; padding-top: 0.5em; }
    </style>
</head>
<body>
    <h1>Decision on Your Appeal</h1>

    <table class="meta-table">
        <tr><td class="label">Decision Date:</td><td>{{ $appeal->internal_decision_at->format('F j, Y') }}</td></tr>
        <tr><td class="label">Participant:</td><td>{{ $participant->first_name }} {{ $participant->last_name }} (MRN {{ $participant->mrn }})</td></tr>
        <tr><td class="label">Appeal Reference:</td><td>APPEAL-{{ $appeal->id }}</td></tr>
        <tr><td class="label">Appeal Type:</td><td>{{ ucfirst($appeal->type) }}</td></tr>
        <tr><td class="label">Filed:</td><td>{{ $appeal->filed_at->format('F j, Y') }}</td></tr>
    </table>

    <h2>Decision</h2>
    @if ($outcome === 'upheld')
        <div class="box outcome-upheld">
            <p><strong>The original denial has been upheld.</strong></p>
            <p>After review, the PACE organization has determined that the original denial of this service
                was appropriate. The service will not be approved at this time.</p>
        </div>
    @elseif ($outcome === 'overturned')
        <div class="box outcome-overturned">
            <p><strong>The original denial has been overturned.</strong></p>
            <p>After review, the PACE organization has determined that the requested service should be
                provided. We will contact you to schedule or arrange this service.</p>
        </div>
    @elseif ($outcome === 'partially_overturned')
        <div class="box outcome-partial">
            <p><strong>The original denial has been partially overturned.</strong></p>
            <p>After review, the PACE organization has determined that a portion of the requested service
                should be provided. The details are described below.</p>
        </div>
    @endif

    <h2>Reasoning</h2>
    <div class="box">
        <p>{{ $appeal->decision_narrative }}</p>
    </div>

    @if ($outcome === 'upheld')
        <h2>Your Right to External Review</h2>
        <div class="box outcome-upheld">
            <p>Because your internal appeal has been denied, you have the right to request an external /
                independent review of this decision. You may also:</p>
            <ul>
                <li>Contact your State Medicaid Agency for a State Fair Hearing (if Medicaid-enrolled).</li>
                <li>Contact Medicare (1-800-MEDICARE / 1-800-633-4227) for further guidance (if Medicare-enrolled).</li>
                <li>Contact the PACE organization's compliance office to initiate the external review process.</li>
            </ul>
            <p>Contact the PACE organization within 30 days of this notice to request external review.</p>
        </div>
    @endif

    <h2>Questions</h2>
    <p>If you have questions about this decision, please contact the PACE organization's enrollment or
        compliance office.</p>

    <div class="footer">
        <p>Decision issued by {{ optional($appeal->decidedBy)->first_name }} {{ optional($appeal->decidedBy)->last_name }}.
            This letter is part of your medical record and is retained per 42 CFR §460.210.</p>
    </div>
</body>
</html>
