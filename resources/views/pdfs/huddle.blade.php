<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Team Huddle — {{ strtoupper($department) }} — {{ $date }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 16px; margin: 0; }
        h2 { font-size: 12px; margin: 10px 0 4px 0; text-transform: uppercase; border-bottom: 1px solid #888; padding-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; font-size: 9px; }
        td, th { padding: 2px 4px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
        th { background: #f4f4f4; font-weight: bold; }
        .meta { font-size: 9px; color: #555; }
        .empty { color: #888; font-style: italic; }
    </style>
</head>
<body>
    <h1>Team Huddle — {{ strtoupper($department) }}</h1>
    <p class="meta">{{ $date }} · generated {{ now()->toDateTimeString() }} UTC</p>

    <h2>Critical alerts ({{ count($critical_alerts) }})</h2>
    @if (count($critical_alerts))
        <table>
            <tr><th>Type</th><th>Participant</th><th>Title</th><th>When</th></tr>
            @foreach ($critical_alerts as $a)
                <tr><td>{{ $a->alert_type }}</td><td>#{{ $a->participant_id }}</td><td>{{ $a->title }}</td><td>{{ $a->created_at->toDateTimeString() }}</td></tr>
            @endforeach
        </table>
    @else <p class="empty">None.</p>
    @endif

    <h2>Overdue tasks ({{ count($overdue_tasks) }})</h2>
    @if (count($overdue_tasks))
        <table>
            <tr><th>Title</th><th>Priority</th><th>Due</th><th>Participant</th></tr>
            @foreach ($overdue_tasks as $t)
                <tr><td>{{ $t->title }}</td><td>{{ $t->priority }}</td><td>{{ $t->due_at?->toDateTimeString() }}</td><td>#{{ $t->participant_id }}</td></tr>
            @endforeach
        </table>
    @else <p class="empty">None.</p>
    @endif

    <h2>New admissions in last 24h ({{ count($new_admissions) }})</h2>
    @if (count($new_admissions))
        <ul>
            @foreach ($new_admissions as $p)
                <li>{{ $p->last_name }}, {{ $p->first_name }} · MRN {{ $p->mrn }} · enrolled {{ $p->enrollment_date }}</li>
            @endforeach
        </ul>
    @else <p class="empty">None.</p>
    @endif

    <h2>New discharges in last 24h ({{ count($new_discharges) }})</h2>
    @if (count($new_discharges))
        <ul>
            @foreach ($new_discharges as $d)
                <li>{{ $d->participant?->last_name }}, {{ $d->participant?->first_name }} — from {{ $d->discharge_from_facility }} on {{ $d->discharged_on }}</li>
            @endforeach
        </ul>
    @else <p class="empty">None.</p>
    @endif

    <h2>Sentinel events in last 24h ({{ count($sentinel_events) }})</h2>
    @if (count($sentinel_events))
        <ul>
            @foreach ($sentinel_events as $s)
                <li>Incident #{{ $s->id }} — {{ $s->incident_type }} · {{ $s->participant?->last_name }}, {{ $s->participant?->first_name }}</li>
            @endforeach
        </ul>
    @else <p class="empty">None.</p>
    @endif

    <h2>Incoming orders ({{ count($incoming_orders) }})</h2>
    @if (count($incoming_orders))
        <table>
            <tr><th>Type</th><th>Priority</th><th>Instructions</th><th>Participant</th></tr>
            @foreach ($incoming_orders as $o)
                <tr><td>{{ $o->order_type }}</td><td>{{ $o->priority }}</td><td>{{ \Illuminate\Support\Str::limit($o->instructions, 80) }}</td><td>#{{ $o->participant_id }}</td></tr>
            @endforeach
        </table>
    @else <p class="empty">None.</p>
    @endif

    <h2>Incoming labs (unreviewed, last 24h) ({{ count($incoming_labs) }})</h2>
    @if (count($incoming_labs))
        <table>
            <tr><th>Panel</th><th>Participant</th><th>When</th></tr>
            @foreach ($incoming_labs as $l)
                <tr><td>{{ $l->panel_name ?? $l->loinc_code ?? '—' }}</td><td>#{{ $l->participant_id }}</td><td>{{ $l->created_at->toDateTimeString() }}</td></tr>
            @endforeach
        </table>
    @else <p class="empty">None.</p>
    @endif
</body>
</html>
