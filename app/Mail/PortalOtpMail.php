<?php

// ─── PortalOtpMail — Phase O10 ──────────────────────────────────────────────
// Branded participant-portal OTP email. Mirrors the staff OtpMail pattern
// but typed to ParticipantPortalUser. Demo deliverability via Mailpit; for
// production a HIPAA-BAA mail vendor must be configured (see paywall report
// item 12: Managed mail provider).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Mail;

use App\Models\ParticipantPortalUser;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PortalOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ParticipantPortalUser $user,
        public readonly string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your NostosEMR Portal Sign-In Code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.portal_otp');
    }

    public function attachments(): array
    {
        return [];
    }
}
