@extends('pdfs._participant_layout')

@section('title', 'Participant Facesheet')

@section('content')
    <h2>Contact</h2>
    @if($address)
        <table class="demographics">
            <tr><td class="k">Address</td><td colspan="3">
                {{ $address->street ?? '' }}@if($address->unit), {{ $address->unit }}@endif<br>
                {{ $address->city ?? '' }} {{ $address->state ?? '' }} {{ $address->zip ?? '' }}
            </td></tr>
            <tr><td class="k">Phone</td><td>{{ $participant->phone_primary ?? '—' }}</td>
                <td class="k">Alt phone</td><td>{{ $participant->phone_secondary ?? '—' }}</td></tr>
        </table>
    @else
        <p class="empty">No primary address on file.</p>
    @endif

    <h2>Active Flags</h2>
    @if($flags->count() === 0)
        <p class="empty">No active flags.</p>
    @else
        <table class="data">
            <thead><tr><th>Type</th><th>Severity</th><th>Description</th></tr></thead>
            <tbody>
            @foreach($flags as $f)
                <tr>
                    <td>{{ ucfirst(str_replace('_', ' ', $f->flag_type)) }}</td>
                    <td>
                        <span class="badge {{ in_array($f->severity, ['high','critical']) ? 'badge-red' : ($f->severity === 'medium' ? 'badge-amber' : 'badge-gray') }}">
                            {{ ucfirst($f->severity) }}
                        </span>
                    </td>
                    <td>{{ $f->description ?? '' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <h2>Advance Directive</h2>
    <table class="demographics">
        <tr><td class="k">Status</td><td>{{ $participant->advanceDirectiveLabel() ?? 'Not documented' }}</td></tr>
        <tr><td class="k">Type</td><td>{{ $participant->advance_directive_type ?? '—' }}</td></tr>
        <tr><td class="k">Reviewed</td><td>{{ optional($participant->advance_directive_reviewed_at)->format('Y-m-d') ?: '—' }}</td></tr>
    </table>

    <h2>Problem List (top 10)</h2>
    @if($problems->count() === 0)
        <p class="empty">No active problems.</p>
    @else
        <table class="data">
            <thead><tr><th>ICD-10</th><th>SNOMED</th><th>Description</th><th>Status</th><th>Onset</th></tr></thead>
            <tbody>
            @foreach($problems as $p)
                <tr>
                    <td>{{ $p->icd10_code }}</td>
                    <td>{{ $p->snomed_code ?? '' }}</td>
                    <td>{{ $p->icd10_description ?? $p->snomed_display }}</td>
                    <td>{{ ucfirst($p->status) }}</td>
                    <td>{{ optional($p->onset_date)->format('Y-m-d') ?: '' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <h2>Active Allergies</h2>
    @if($allergies->count() === 0)
        <p class="empty">No known allergies recorded.</p>
    @else
        <table class="data">
            <thead><tr><th>Allergen</th><th>RxNorm</th><th>Reaction</th><th>Severity</th></tr></thead>
            <tbody>
            @foreach($allergies as $a)
                <tr>
                    <td>{{ $a->allergen_name }}</td>
                    <td>{{ $a->rxnorm_code ?? '' }}</td>
                    <td>{{ $a->reaction_description ?? '' }}</td>
                    <td>
                        <span class="badge {{ in_array($a->severity, ['severe','life_threatening']) ? 'badge-red' : 'badge-amber' }}">
                            {{ ucfirst(str_replace('_', ' ', (string)$a->severity)) }}
                        </span>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <h2>Active Medications (top 15)</h2>
    @if($medications->count() === 0)
        <p class="empty">No active medications.</p>
    @else
        <table class="data">
            <thead><tr><th>Drug</th><th>Dose</th><th>Route</th><th>Frequency</th></tr></thead>
            <tbody>
            @foreach($medications as $m)
                <tr>
                    <td>{{ $m->drug_name }}</td>
                    <td>{{ trim(($m->dose ?? '') . ' ' . ($m->dose_unit ?? '')) }}</td>
                    <td>{{ $m->route ?? '' }}</td>
                    <td>{{ $m->frequency ?? '' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endsection
