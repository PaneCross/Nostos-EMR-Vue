<?php

// ─── CredentialExpiringMail ──────────────────────────────────────────────────
// Sent to the staff member (and at later cadence steps, their supervisor) when
// a credential is approaching expiration. Per HIPAA convention, the subject
// stays generic ; details live in the body which is delivered to the staff
// member's own email about their own credential (no participant PHI).
//
// Payload: Credential title, expiry date, days remaining, status, link.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Mail;

use App\Models\StaffCredential;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CredentialExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $recipient,
        public readonly StaffCredential $credential,
        public readonly int $daysRemaining,
        public readonly bool $isSupervisorCopy = false,
    ) {}

    public function envelope(): Envelope
    {
        if ($this->daysRemaining < 0) {
            return new Envelope(subject: 'Action required : credential overdue : NostosEMR');
        }
        return new Envelope(subject: 'Reminder : credential expiring soon : NostosEMR');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.credential-expiring',
            with: [
                'recipientName' => $this->recipient->first_name,
                'credentialTitle' => $this->credential->title,
                'expiresAt' => $this->credential->expires_at?->format('M j, Y'),
                'daysRemaining' => $this->daysRemaining,
                'isOverdue' => $this->daysRemaining < 0,
                'isSupervisorCopy' => $this->isSupervisorCopy,
                'staffName' => "{$this->credential->user->first_name} {$this->credential->user->last_name}",
            ],
        );
    }
}
