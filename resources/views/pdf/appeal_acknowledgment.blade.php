{{-- Appeal Acknowledgment Letter per §460.122 --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Appeal Acknowledgment</title>
    <style>
        @page { margin: 0.75in; }
        body { font-family: DejaVu Sans, Helvetica, sans-serif; font-size: 11pt; color: #111; line-height: 1.4; }
        h1 { font-size: 16pt; margin: 0 0 0.25em; }
        h2 { font-size: 12pt; margin: 1em 0 0.25em; border-bottom: 1px solid #888; padding-bottom: 2px; }
        .box { border: 1px solid #888; padding: 0.75em; margin: 1em 0; background: #f7f7f7; }
        table.meta-table { width: 100%; font-size: 10pt; margin-bottom: 1em; }
        table.meta-table td { padding: 2px 6px; vertical-align: top; }
        table.meta-table td.label { color: #555; width: 32%; }
        .footer { margin-top: 2em; font-size: 9pt; color: #666; border-top: 1px solid #ccc; padding-top: 0.5em; }
    </style>
</head>
<body>
    <h1>Acknowledgment of Appeal</h1>

    <table class="meta-table">
        <tr><td class="label">Acknowledgment Date:</td><td>{{ now()->format('F j, Y') }}</td></tr>
        <tr><td class="label">Participant:</td><td>{{ $participant->first_name }} {{ $participant->last_name }} (MRN {{ $participant->mrn }})</td></tr>
        <tr><td class="label">Appeal Reference:</td><td>APPEAL-{{ $appeal->id }}</td></tr>
        <tr><td class="label">Appeal Type:</td><td>{{ ucfirst($appeal->type) }} ({{ $appeal->type === 'expedited' ? '72-hour decision' : '30-day decision' }})</td></tr>
        <tr><td class="label">Filed:</td><td>{{ $appeal->filed_at->format('F j, Y g:i A') }}</td></tr>
        <tr><td class="label">Decision Due By:</td><td><strong>{{ $appeal->internal_decision_due_at->format('F j, Y g:i A') }}</strong></td></tr>
    </table>

    <p>We have received your appeal of our decision described in Notice DENIAL-{{ $appeal->service_denial_notice_id }}.
        This letter confirms that your appeal is now under review.</p>

    <h2>What Happens Next</h2>
    <div class="box">
        <p>A qualified reviewer who was not involved in the original decision will review your case. You
            will receive a written decision by the date shown above.</p>
        @if ($appeal->continuation_of_benefits)
            <p><strong>Continuation of services:</strong> you requested that services continue during
                your appeal. The PACE organization will continue those services until a decision is made,
                per 42 CFR §460.122.</p>
        @endif
    </div>

    <h2>If You Need to Add Information</h2>
    <p>You may submit additional documents, statements, or witness information at any time before the
        decision date. Contact the PACE organization's enrollment or compliance office.</p>

    <div class="footer">
        <p>This acknowledgment is part of your medical record and is retained per 42 CFR §460.210.</p>
    </div>
</body>
</html>
