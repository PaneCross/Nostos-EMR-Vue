{{-- Credentials packet PDF - per-staff surveyor artifact (D5) --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #222; margin: 0; padding: 24px; }
        h1 { font-size: 18pt; margin: 0 0 4px 0; color: #4f46e5; }
        h2 { font-size: 12pt; margin: 18px 0 8px 0; color: #1f2937; border-bottom: 1px solid #d1d5db; padding-bottom: 4px; }
        .meta { font-size: 9pt; color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-weight: bold; }
        .pill { display: inline-block; padding: 2px 6px; border-radius: 8px; font-size: 8pt; font-weight: bold; }
        .pill-active { background: #d1fae5; color: #065f46; }
        .pill-expiring { background: #fef3c7; color: #92400e; }
        .pill-expired { background: #fee2e2; color: #991b1b; }
        .pill-pending { background: #fef3c7; color: #92400e; }
        .pill-invalid { background: #fecaca; color: #991b1b; }
        .pill-mand { background: #fee2e2; color: #991b1b; }
        .pill-psv { background: #fef3c7; color: #92400e; }
        .footer { margin-top: 24px; font-size: 8pt; color: #6b7280; border-top: 1px solid #d1d5db; padding-top: 8px; }
        .missing { background: #fef2f2; border: 1px solid #fecaca; padding: 8px 12px; border-radius: 6px; margin-bottom: 12px; }
    </style>
</head>
<body>
    <h1>Staff Credentials Packet</h1>
    <div class="meta">
        <strong>{{ $user->first_name }} {{ $user->last_name }}</strong>
        &nbsp;|&nbsp; {{ ucwords(str_replace('_', ' ', $user->department)) }}
        @if($user->job_title) &nbsp;|&nbsp; {{ $user->job_title }} @endif
        &nbsp;|&nbsp; {{ $user->email }}
        <br>
        Generated {{ $generatedAt->format('Y-m-d H:i') }} by {{ $generatedBy->first_name }} {{ $generatedBy->last_name }}
        @if($tenant) &nbsp;|&nbsp; {{ $tenant->name }} @endif
    </div>

    @if($missing->isNotEmpty())
        <div class="missing">
            <strong>Missing required credentials ({{ $missing->count() }}):</strong>
            <ul style="margin: 4px 0 0 16px; padding: 0;">
                @foreach($missing as $m)
                    <li>{{ $m->title }} @if($m->is_cms_mandatory) <span class="pill pill-mand">CMS-mandatory</span> @endif</li>
                @endforeach
            </ul>
        </div>
    @endif

    <h2>On-file credentials ({{ $credentials->count() }})</h2>
    @if($credentials->isEmpty())
        <p><em>No credentials on file.</em></p>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width: 38%;">Credential</th>
                    <th style="width: 14%;">Type</th>
                    <th style="width: 14%;">License #</th>
                    <th style="width: 11%;">Issued</th>
                    <th style="width: 11%;">Expires</th>
                    <th style="width: 12%;">Status / Doc</th>
                </tr>
            </thead>
            <tbody>
                @foreach($credentials as $c)
                    <tr>
                        <td>
                            {{ $c->title }}
                            @if($c->definition?->is_cms_mandatory) <span class="pill pill-mand">CMS</span> @endif
                            @if($c->definition?->requires_psv) <span class="pill pill-psv">PSV</span> @endif
                            @if($c->verification_source)
                                <br><span style="font-size: 8pt; color: #6b7280;">via {{ \App\Models\StaffCredential::VERIFICATION_SOURCES[$c->verification_source] ?? $c->verification_source }}</span>
                            @endif
                        </td>
                        <td>{{ \App\Models\StaffCredential::TYPE_LABELS[$c->credential_type] ?? $c->credential_type }}</td>
                        <td>
                            @if($c->license_state) {{ $c->license_state }} @endif
                            @if($c->license_number) {{ $c->license_number }} @endif
                            @if(!$c->license_state && !$c->license_number) - @endif
                        </td>
                        <td>{{ $c->issued_at?->format('Y-m-d') ?? '-' }}</td>
                        <td>{{ $c->expires_at?->format('Y-m-d') ?? '-' }}</td>
                        <td>
                            @php $st = $c->status(); @endphp
                            <span class="pill pill-{{ in_array($st, ['expired','suspended','revoked']) ? 'expired' : (in_array($st, ['due_today','due_14','due_30','due_60']) ? 'expiring' : ($c->cms_status === 'pending' ? 'pending' : 'active')) }}">
                                {{ strtoupper(str_replace('_', ' ', $st)) }}
                            </span>
                            <br>
                            @if($c->document_path)
                                <span style="font-size: 8pt; color: #065f46;">✓ doc on file</span>
                            @else
                                <span style="font-size: 8pt; color: #991b1b;">no doc</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        This packet reflects the current state of {{ $user->first_name }} {{ $user->last_name }}'s personnel
        credentials. Per 42 CFR §460.71, staff qualifications and training records are subject to CMS audit.
        Document copies are retained in NostosEMR and available on request.
    </div>
</body>
</html>
