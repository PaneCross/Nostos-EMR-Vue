<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $type_label }}: {{ $participant->first_name }} {{ $participant->last_name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 11pt; margin: 0; padding: 0; }
        .wrap { padding: 20pt 24pt; }
        h1 { font-size: 16pt; margin: 0 0 4pt 0; }
        h2 { font-size: 12pt; margin: 14pt 0 4pt 0; border-bottom: 1px solid #cbd5e1; padding-bottom: 2pt; }
        .sub { font-size: 9pt; color: #475569; margin-bottom: 10pt; }
        table.meta { width: 100%; border-collapse: collapse; margin-top: 4pt; }
        table.meta td { padding: 3pt 4pt; vertical-align: top; border: 1px solid #cbd5e1; }
        table.meta td.k { background: #f1f5f9; font-weight: bold; width: 28%; }
        .box { border: 1px solid #94a3b8; padding: 8pt 10pt; margin-top: 8pt; border-radius: 2pt; }
        ol.choices { margin: 4pt 0 8pt 18pt; padding: 0; }
        ol.choices li { margin-bottom: 4pt; }
        .signature-line { display: inline-block; border-bottom: 1px solid #0f172a; width: 220pt; height: 14pt; }
        .sig-row { display: flex; gap: 18pt; margin-top: 10pt; font-size: 10pt; }
        .sig-row > div { flex: 1; }
        .facsimile {
            position: fixed; bottom: 6pt; left: 24pt; right: 24pt; text-align: center;
            font-size: 8pt; color: #b91c1c; border-top: 1px solid #fca5a5; padding-top: 4pt;
        }
    </style>
</head>
<body>
<div class="wrap">
    <h1>{{ $type_label }}</h1>
    <div class="sub">NostosEMR PACE Program: generated {{ $generated_at->format('F j, Y H:i') }}</div>

    <h2>Participant</h2>
    <table class="meta">
        <tr><td class="k">Name</td><td>{{ $participant->first_name }} {{ $participant->last_name }}</td></tr>
        <tr><td class="k">MRN</td><td>{{ $participant->mrn ?? '-' }}</td></tr>
        <tr><td class="k">Date of Birth</td><td>{{ optional($participant->dob)->format('F j, Y') ?: '-' }}</td></tr>
        <tr><td class="k">Gender</td><td>{{ ucfirst((string)($participant->gender ?? '-')) }}</td></tr>
        <tr><td class="k">Address</td><td>
            @if($address)
                {{ $address->street ?? '' }}@if($address->unit) , {{ $address->unit }}@endif<br>
                {{ $address->city ?? '' }} {{ $address->state ?? '' }} {{ $address->zip ?? '' }}
            @else
               :
            @endif
        </td></tr>
        <tr><td class="k">Current Directive on File</td><td>{{ $participant->advanceDirectiveLabel() ?? 'None documented' }}</td></tr>
    </table>

    @if($type === 'dnr' || $type === 'combined')
        <h2>Do Not Resuscitate (DNR) Order</h2>
        <div class="box">
            <div>I, <strong>{{ $participant->first_name }} {{ $participant->last_name }}</strong>, direct that in the event of cardiac or pulmonary arrest, no resuscitative measures be initiated. This includes, but is not limited to:</div>
            <ol class="choices">
                <li>Chest compressions</li>
                <li>Defibrillation / cardioversion</li>
                <li>Intubation or mechanical ventilation</li>
                <li>Advanced cardiac medications intended to restart the heart</li>
            </ol>
            <div>Comfort care, pain management, and oxygen for relief of dyspnea <em>shall</em> continue to be provided.</div>
        </div>
    @endif

    @if($type === 'polst' || $type === 'combined')
        <h2>POLST: Life-Sustaining Treatment Orders</h2>
        <div class="box">
            <div><strong>Section A: CPR status (when without pulse AND not breathing):</strong></div>
            <ol class="choices">
                <li>☐ Attempt Resuscitation / CPR</li>
                <li>☐ Do Not Attempt Resuscitation / DNR (Allow Natural Death)</li>
            </ol>
            <div style="margin-top: 6pt;"><strong>Section B: Medical Interventions (when pulse and/or breathing present):</strong></div>
            <ol class="choices">
                <li>☐ Full Treatment: all interventions including intubation &amp; ICU care</li>
                <li>☐ Selective Treatment: medical treatment, IV fluids/antibiotics, no intubation</li>
                <li>☐ Comfort-Focused Treatment: symptom relief only</li>
            </ol>
            <div style="margin-top: 6pt;"><strong>Section C: Medically Assisted Nutrition:</strong></div>
            <ol class="choices">
                <li>☐ Long-term artificial nutrition</li>
                <li>☐ Trial period of artificial nutrition</li>
                <li>☐ No artificial nutrition</li>
            </ol>
        </div>
    @endif

    @if($type === 'healthcare_proxy' || $type === 'combined')
        <h2>Healthcare Proxy / Agent</h2>
        <div class="box">
            <div>I designate the following person as my healthcare agent to make healthcare decisions for me if I become unable to do so:</div>
            <table class="meta" style="margin-top: 4pt;">
                <tr><td class="k">Agent Name</td><td>&nbsp;</td></tr>
                <tr><td class="k">Relationship</td><td>&nbsp;</td></tr>
                <tr><td class="k">Phone</td><td>&nbsp;</td></tr>
                <tr><td class="k">Address</td><td>&nbsp;</td></tr>
            </table>
        </div>
    @endif

    @if($type === 'living_will' || $type === 'combined')
        <h2>Living Will: Treatment Preferences</h2>
        <div class="box">
            <ol class="choices">
                <li>If I have a terminal condition or am in a persistent vegetative state, I direct that treatments that only prolong dying be withheld or withdrawn.</li>
                <li>I wish to receive pain medication and comfort care at all times, even if such care may shorten life.</li>
                <li>Other specific instructions: ______________________________________________________</li>
            </ol>
        </div>
    @endif

    <h2>Signatures</h2>
    <div class="sig-row">
        <div>Participant: <span class="signature-line"></span><br>Date: ____________</div>
        <div>Physician / Provider: <span class="signature-line"></span><br>Date: ____________</div>
    </div>
    <div class="sig-row">
        <div>Witness 1: <span class="signature-line"></span><br>Date: ____________</div>
        <div>Witness 2 / Notary: <span class="signature-line"></span><br>Date: ____________</div>
    </div>
</div>

<div class="facsimile">
    PACE-generated facsimile: confirm conformance to state-specific advance directive requirements before legal reliance.
</div>
</body>
</html>
