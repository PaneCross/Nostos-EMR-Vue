{{-- credential-digest email body : G4 batched reminder when multiple --}}
{{-- credentials hit reminder steps on the same job run.                --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Credential reminders</title>
</head>
<body style="font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #4f46e5; margin-bottom: 8px;">Multiple credentials need attention</h2>
    <p>Hi {{ $recipientName }},</p>
    <p>The following credentials on your record are approaching expiration or already overdue. Bundling them into one email so you don't get a flood :</p>

    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <thead>
            <tr style="background: #f3f4f6;">
                <th style="text-align: left; padding: 8px 12px; border-bottom: 1px solid #d1d5db;">Credential</th>
                <th style="text-align: left; padding: 8px 12px; border-bottom: 1px solid #d1d5db;">Expires</th>
                <th style="text-align: left; padding: 8px 12px; border-bottom: 1px solid #d1d5db;">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                @php $cred = $item['credential']; $days = $item['days_remaining']; @endphp
                <tr>
                    <td style="padding: 8px 12px; border-bottom: 1px solid #e5e7eb;">
                        {{ $cred->title }}
                        @if(($item['is_supervisor_copy'] ?? false))
                            <br><span style="font-size: 11px; color: #6b7280;">(direct report : {{ $cred->user->first_name ?? '' }} {{ $cred->user->last_name ?? '' }})</span>
                        @endif
                    </td>
                    <td style="padding: 8px 12px; border-bottom: 1px solid #e5e7eb;">{{ $cred->expires_at?->format('M j, Y') }}</td>
                    <td style="padding: 8px 12px; border-bottom: 1px solid #e5e7eb; color: {{ $days < 0 ? '#dc2626' : '#d97706' }};">
                        @if($days < 0)
                            {{ abs($days) }} days overdue
                        @elseif($days === 0)
                            Due today
                        @else
                            {{ $days }} days
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p style="margin-top: 24px;">
        <a href="{{ url('/my-credentials') }}" style="background: #4f46e5; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px; display: inline-block;">
            Open My Credentials
        </a>
    </p>

    <p style="font-size: 12px; color: #6b7280; margin-top: 32px; border-top: 1px solid #e5e7eb; padding-top: 16px;">
        Required by 42 CFR §460.71. You can adjust delivery preferences from the user menu.
    </p>
</body>
</html>
