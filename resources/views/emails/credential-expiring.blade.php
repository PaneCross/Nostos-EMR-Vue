{{-- ─── credential-expiring email body ──────────────────────────────────── --}}
{{-- Sent to staff member (and supervisor at 14d). Plain HTML, no PHI. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Credential reminder</title>
</head>
<body style="font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #4f46e5; margin-bottom: 8px;">
        @if($isOverdue)
            Credential OVERDUE
        @else
            Credential expiring soon
        @endif
    </h2>

    <p>Hi {{ $recipientName }},</p>

    @if($isSupervisorCopy)
        <p>This is a courtesy supervisor notice. Your direct report <strong>{{ $staffName }}</strong> has a credential approaching expiration:</p>
    @else
        <p>This is a reminder about one of your credentials on file:</p>
    @endif

    <table style="width: 100%; border-collapse: collapse; margin: 16px 0; background: #f9fafb;">
        <tr>
            <td style="padding: 8px 12px; font-weight: bold;">Credential</td>
            <td style="padding: 8px 12px;">{{ $credentialTitle }}</td>
        </tr>
        @if($expiresAt)
            <tr>
                <td style="padding: 8px 12px; font-weight: bold;">Expires</td>
                <td style="padding: 8px 12px;">{{ $expiresAt }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding: 8px 12px; font-weight: bold;">Status</td>
            <td style="padding: 8px 12px; color: {{ $isOverdue ? '#dc2626' : '#d97706' }};">
                @if($isOverdue)
                    {{ abs($daysRemaining) }} days overdue
                @elseif($daysRemaining === 0)
                    Due today
                @else
                    Expires in {{ $daysRemaining }} days
                @endif
            </td>
        </tr>
    </table>

    @if(!$isSupervisorCopy)
        <p>To upload a renewal document, log in to NostosEMR and visit <strong>My Credentials</strong> from the user menu.</p>
        <p style="margin-top: 24px;">
            <a href="{{ url('/my-credentials') }}" style="background: #4f46e5; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px; display: inline-block;">
                Open My Credentials
            </a>
        </p>
    @else
        <p>Please follow up with {{ $staffName }} to ensure the renewal is processed.</p>
    @endif

    <p style="font-size: 12px; color: #6b7280; margin-top: 32px; border-top: 1px solid #e5e7eb; padding-top: 16px;">
        This reminder is part of NostosEMR's automated credential tracking. You can adjust delivery preferences from your user menu.
        Required by 42 CFR §460.71 and CMS Personnel Audit Protocol.
    </p>
</body>
</html>
