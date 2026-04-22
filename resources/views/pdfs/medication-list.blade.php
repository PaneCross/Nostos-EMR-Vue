@extends('pdfs._participant_layout')

@section('title', 'Active Medication List')

@section('content')
    <h2>Active Medications ({{ $medications->count() }})</h2>
    @if($medications->count() === 0)
        <p class="empty">No active medications on file.</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th>Drug</th>
                    <th>RxNorm</th>
                    <th>Dose</th>
                    <th>Route</th>
                    <th>Frequency</th>
                    <th>Prescribed</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            @foreach($medications as $m)
                <tr>
                    <td>
                        {{ $m->drug_name }}
                        @if($m->is_controlled)
                            <span class="badge badge-red">Schedule {{ $m->controlled_schedule }}</span>
                        @endif
                        @if($m->is_prn)
                            <span class="badge badge-amber">PRN</span>
                        @endif
                    </td>
                    <td>{{ $m->rxnorm_code ?? '' }}</td>
                    <td>{{ trim(($m->dose ?? '') . ' ' . ($m->dose_unit ?? '')) }}</td>
                    <td>{{ $m->route ?? '' }}</td>
                    <td>
                        {{ $m->frequency ?? '' }}
                        @if($m->is_prn && $m->prn_indication)
                            <br><span style="color:#64748b; font-size: 8.5pt;">for {{ $m->prn_indication }}</span>
                        @endif
                    </td>
                    <td>{{ optional($m->prescribed_date)->format('Y-m-d') ?: '' }}</td>
                    <td>{{ ucfirst($m->status) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <h2>Known Allergies</h2>
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
                    <td>{{ ucfirst(str_replace('_', ' ', (string)$a->severity)) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endsection
