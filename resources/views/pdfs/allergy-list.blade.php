@extends('pdfs._participant_layout')

@section('title', 'Allergy List')

@section('content')
    <h2>Active Allergies ({{ $allergies->where('is_active', true)->count() }})</h2>
    @if($allergies->where('is_active', true)->count() === 0)
        <p class="empty">No known active allergies.</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th>Allergen</th>
                    <th>Type</th>
                    <th>RxNorm</th>
                    <th>Reaction</th>
                    <th>Severity</th>
                    <th>Onset</th>
                </tr>
            </thead>
            <tbody>
            @foreach($allergies->where('is_active', true) as $a)
                <tr>
                    <td><strong>{{ $a->allergen_name }}</strong></td>
                    <td>{{ ucfirst((string)$a->allergy_type) }}</td>
                    <td>{{ $a->rxnorm_code ?? '' }}</td>
                    <td>{{ $a->reaction_description ?? '' }}</td>
                    <td>
                        <span class="badge {{ in_array($a->severity, ['severe','life_threatening']) ? 'badge-red' : 'badge-amber' }}">
                            {{ ucfirst(str_replace('_', ' ', (string)$a->severity)) }}
                        </span>
                    </td>
                    <td>{{ optional($a->onset_date)->format('Y-m-d') ?: '' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @php $inactive = $allergies->where('is_active', false); @endphp
    @if($inactive->count() > 0)
        <h2>Inactive / Resolved Allergies ({{ $inactive->count() }})</h2>
        <table class="data">
            <thead><tr><th>Allergen</th><th>Reaction</th><th>Severity</th><th>Notes</th></tr></thead>
            <tbody>
            @foreach($inactive as $a)
                <tr>
                    <td>{{ $a->allergen_name }}</td>
                    <td>{{ $a->reaction_description ?? '' }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', (string)$a->severity)) }}</td>
                    <td>{{ $a->notes ?? '' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endsection
