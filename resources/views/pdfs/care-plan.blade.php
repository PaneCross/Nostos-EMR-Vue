@extends('pdfs._participant_layout')

@section('title', 'Care Plan')

@section('content')
    @if(! $carePlan)
        <p class="empty">No active care plan on file.</p>
    @else
        <table class="demographics">
            <tr>
                <td class="k">Version</td><td>{{ $carePlan->version ?? '1' }}</td>
                <td class="k">Status</td><td>{{ ucfirst(str_replace('_', ' ', (string) $carePlan->status)) }}</td>
            </tr>
            <tr>
                <td class="k">Effective</td><td>{{ optional($carePlan->effective_date)->format('Y-m-d') ?: '—' }}</td>
                <td class="k">Review due</td><td>{{ optional($carePlan->review_due_date)->format('Y-m-d') ?: '—' }}</td>
            </tr>
            <tr>
                <td class="k">Approved by</td>
                <td>
                    {{ $carePlan->approvedBy ? $carePlan->approvedBy->first_name . ' ' . $carePlan->approvedBy->last_name : '—' }}
                </td>
                <td class="k">Approved at</td>
                <td>{{ optional($carePlan->approved_at)->format('Y-m-d H:i') ?: '—' }}</td>
            </tr>
        </table>

        <h2>Overall Goals</h2>
        @if($carePlan->overall_goals_text)
            <div style="padding: 6pt 8pt; border: 1px solid #e2e8f0; background: #f8fafc; white-space: pre-wrap; font-size: 9.5pt;">{{ $carePlan->overall_goals_text }}</div>
        @else
            <p class="empty">No overall goals documented.</p>
        @endif

        <h2>Goals + Interventions ({{ $carePlan->goals->count() }})</h2>
        @if($carePlan->goals->count() === 0)
            <p class="empty">No domain goals on this plan.</p>
        @else
            <table class="data">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th>Goal</th>
                        <th>Interventions</th>
                        <th>Status</th>
                        <th>Target</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($carePlan->goals as $g)
                    <tr>
                        <td>{{ ucfirst(str_replace('_', ' ', (string) ($g->domain ?? ''))) }}</td>
                        <td>{{ $g->goal_text ?? $g->description ?? '' }}</td>
                        <td style="white-space: pre-wrap; font-size: 9pt;">{{ $g->interventions ?? '' }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', (string) ($g->status ?? ''))) }}</td>
                        <td>{{ optional($g->target_date ?? null)->format('Y-m-d') ?: '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        @if($carePlan->participant_offered_participation !== null)
            <h2>Participant Acknowledgment</h2>
            <table class="demographics">
                <tr>
                    <td class="k">Offered participation</td>
                    <td>{{ $carePlan->participant_offered_participation ? 'Yes' : 'No' }}</td>
                </tr>
                @if($carePlan->participant_response)
                    <tr>
                        <td class="k">Response</td>
                        <td>{{ $carePlan->participant_response }}</td>
                    </tr>
                @endif
                @if($carePlan->offered_at)
                    <tr>
                        <td class="k">Offered at</td>
                        <td>{{ $carePlan->offered_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @endif
            </table>
        @endif
    @endif
@endsection
