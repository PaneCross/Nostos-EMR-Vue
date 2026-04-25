<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Notice of Breach of Protected Health Information</title>
    <style>
        @page { margin: 1in; }
        body { font-family: 'DejaVu Serif', serif; font-size: 11pt; line-height: 1.4; color: #111; }
        h1 { font-size: 14pt; text-align: center; margin-bottom: 24pt; }
        .org-block { margin-bottom: 18pt; }
        .meta { margin-bottom: 18pt; }
        .meta dt { font-weight: bold; display: inline; }
        .meta dd { display: inline; margin: 0 8pt 0 4pt; }
        .meta dd::after { content: '\A'; white-space: pre; }
        .section { margin-top: 14pt; }
        .section h2 { font-size: 11pt; text-transform: uppercase; letter-spacing: 0.5pt; margin-bottom: 6pt; }
        .signature { margin-top: 40pt; }
        .signature-line { border-top: 1px solid #444; width: 240pt; margin-top: 36pt; padding-top: 4pt; }
        .small { font-size: 9pt; color: #555; }
        .underline { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="org-block">
        <strong>{{ $tenant_name ?? 'NostosEMR PACE Organization' }}</strong><br>
        {{ $tenant_address ?? '[Tenant Address]' }}<br>
        {{ $tenant_phone ?? '[Tenant Phone]' }}
    </div>

    <div>
        {{ now()->format('F j, Y') }}
    </div>

    <div style="margin-top:24pt">
        {{ $participant->first_name }} {{ $participant->last_name }}<br>
        {{ $address ?? '[Address on file]' }}
    </div>

    <h1>Notice of Breach of Protected Health Information</h1>

    <p>Dear {{ $participant->first_name }} {{ $participant->last_name }},</p>

    <p>We are writing to inform you of a recent incident that may have involved your protected
    health information (PHI). The Health Insurance Portability and Accountability Act (HIPAA)
    requires that we notify you of this breach.</p>

    <div class="section">
        <h2>What happened</h2>
        <p>{{ $breach->description }}</p>
        <dl class="meta">
            <dt>Date discovered:</dt><dd>{{ $breach->discovered_at?->format('F j, Y') }}</dd>
            @if ($breach->occurred_at)
                <dt>Date occurred:</dt><dd>{{ $breach->occurred_at?->format('F j, Y') }}</dd>
            @endif
            <dt>Type of breach:</dt><dd>{{ str_replace('_', ' ', $breach->breach_type) }}</dd>
        </dl>
    </div>

    <div class="section">
        <h2>What information was involved</h2>
        <p>The protected health information potentially affected may have included one or more
        of: name, date of birth, medical record number, diagnoses, treatment information, and
        other clinical records on file with our organization.</p>
    </div>

    <div class="section">
        <h2>What we are doing</h2>
        <p>{{ $breach->mitigation_taken ?: 'We are conducting a thorough review of how this incident occurred and have implemented additional safeguards to prevent recurrence. We have notified the U.S. Department of Health and Human Services Office for Civil Rights as required.' }}</p>
    </div>

    <div class="section">
        <h2>What you can do</h2>
        <p>We recommend you remain alert for any suspicious activity related to your medical
        accounts and health insurance benefits. Review your insurance Explanation of Benefits
        statements for any care you did not receive. If you have questions or believe your
        information has been misused, please contact us at the number above.</p>
    </div>

    <div class="section">
        <h2>For more information</h2>
        <p>You may also file a complaint with the U.S. Department of Health and Human Services,
        Office for Civil Rights, by visiting <span class="underline">https://www.hhs.gov/hipaa/filing-a-complaint/</span> or by mail at
        200 Independence Avenue, S.W., Washington, D.C. 20201.</p>
    </div>

    <p>We sincerely regret this incident and any inconvenience it may cause you.</p>

    <div class="signature">
        <p>Sincerely,</p>
        <div class="signature-line">{{ $signer_name ?? 'Privacy Officer' }}<br>{{ $signer_title ?? 'Privacy Officer' }}</div>
    </div>

    <div class="small" style="margin-top:36pt; border-top:1px solid #ccc; padding-top:8pt;">
        Generated {{ now()->format('Y-m-d H:i T') }} from breach incident #{{ $breach->id }} by NostosEMR.
        This letter satisfies the individual-notification requirement of HIPAA §164.404.
    </div>
</body>
</html>
