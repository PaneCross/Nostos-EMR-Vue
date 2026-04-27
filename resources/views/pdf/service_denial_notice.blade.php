{{--
    Service Denial Notice: CMS-style letter per 42 CFR §460.122.
    Must include: reason, appeal rights, external review path, appeal deadline.
    Rendered to PDF by ServiceDenialNoticeService::generatePdf().
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Notice of Service Denial</title>
    <style>
        @page { margin: 0.75in; }
        body { font-family: DejaVu Sans, Helvetica, sans-serif; font-size: 11pt; color: #111; line-height: 1.4; }
        h1 { font-size: 16pt; margin: 0 0 0.25em; }
        h2 { font-size: 12pt; margin: 1em 0 0.25em; border-bottom: 1px solid #888; padding-bottom: 2px; }
        p { margin: 0.4em 0; }
        .meta { font-size: 10pt; color: #555; }
        .box { border: 1px solid #888; padding: 0.75em; margin: 1em 0; background: #f7f7f7; }
        .rights { background: #fff7e6; border-color: #c48400; }
        table.meta-table { width: 100%; font-size: 10pt; margin-bottom: 1em; }
        table.meta-table td { padding: 2px 6px; vertical-align: top; }
        table.meta-table td.label { color: #555; width: 32%; }
        .footer { margin-top: 2em; font-size: 9pt; color: #666; border-top: 1px solid #ccc; padding-top: 0.5em; }
    </style>
</head>
<body>
    <h1>Notice of Service Denial</h1>
    <p class="meta">42 CFR §460.122: Participant Appeal Rights</p>

    <table class="meta-table">
        <tr>
            <td class="label">Notice Date:</td>
            <td>{{ $notice->issued_at->format('F j, Y') }}</td>
        </tr>
        <tr>
            <td class="label">Participant:</td>
            <td>{{ $participant->first_name }} {{ $participant->last_name }} (MRN {{ $participant->mrn }})</td>
        </tr>
        <tr>
            <td class="label">Date of Birth:</td>
            <td>{{ optional($participant->dob)->format('F j, Y') ?? '-' }}</td>
        </tr>
        @if ($notice->sdr)
            <tr>
                <td class="label">Request ID:</td>
                <td>SDR-{{ $notice->sdr->id }} ({{ \App\Models\Sdr::TYPE_LABELS[$notice->sdr->request_type] ?? $notice->sdr->request_type }})</td>
            </tr>
            <tr>
                <td class="label">Service Requested:</td>
                <td>{{ $notice->sdr->description }}</td>
            </tr>
        @endif
    </table>

    <h2>Decision</h2>
    <p>Your request for the service described above has been <strong>denied</strong> by the PACE organization.</p>

    <h2>Reason for Denial</h2>
    <div class="box">
        <p><strong>{{ $notice->reason_code }}</strong></p>
        <p>{{ $notice->reason_narrative }}</p>
    </div>

    <h2>Your Right to Appeal</h2>
    <div class="box rights">
        <p>You have the right to appeal this decision. You must file your appeal by
            <strong>{{ $notice->appeal_deadline_at->format('F j, Y') }}</strong>
            ({{ \App\Models\ServiceDenialNotice::APPEAL_DEADLINE_DAYS }} days from this notice).</p>

        <p><strong>Standard Appeal:</strong> a written decision will be made within 30 days of receipt.</p>
        <p><strong>Expedited Appeal:</strong> if waiting 30 days would seriously jeopardize your life, health,
            or ability to regain maximum function, you may request an expedited appeal. A decision will be
            made within 72 hours of receipt.</p>

        <p><strong>Continuation of Services:</strong> if you are appealing a decision to reduce, suspend,
            or terminate a service you are currently receiving, you may request that the service continue
            during your appeal. You must request this at the time you file your appeal.</p>

        <p><strong>External / Independent Review:</strong> if your internal appeal is denied, you have the
            right to request an external/independent review. Instructions for external review will be
            included with the decision letter.</p>
    </div>

    <h2>How to File an Appeal</h2>
    <p>You may file an appeal verbally or in writing. Contact the PACE organization's enrollment or
        compliance office. You may appoint a representative (including a family member, caregiver, or
        attorney) to file on your behalf with a signed written authorization.</p>

    <h2>Questions</h2>
    <p>If you have questions about this notice or need help filing an appeal, please contact the PACE
        organization. You also have the right to contact your State Medicaid Agency or 1-800-MEDICARE
        (1-800-633-4227) for assistance.</p>

    <div class="footer">
        <p>Notice issued by {{ $issuedBy->first_name ?? '' }} {{ $issuedBy->last_name ?? '' }}
            on behalf of the PACE organization. This notice is a part of your medical record and is
            retained per 42 CFR §460.210.</p>
    </div>
</body>
</html>
