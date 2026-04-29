<?php

// ─── CredentialDigestMail ────────────────────────────────────────────────────
// G4 : when a single user has multiple credentials hitting reminder steps on
// the same job run, batch them into one email instead of N. CredentialExpiringMail
// is still used for single-credential cases ; this is the multi-credential
// variant.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Mail;

use App\Models\StaffCredential;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CredentialDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param User $recipient The staff member or supervisor receiving the digest
     * @param array $items each item: ['credential' => StaffCredential, 'days_remaining' => int, 'is_supervisor_copy' => bool]
     */
    public function __construct(
        public readonly User $recipient,
        public readonly array $items,
    ) {}

    public function envelope(): Envelope
    {
        $count = count($this->items);
        $hasOverdue = collect($this->items)->some(fn ($i) => $i['days_remaining'] < 0);
        $subject = $hasOverdue
            ? "Action required : {$count} credentials need attention : NostosEMR"
            : "Reminder : {$count} credentials expiring soon : NostosEMR";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.credential-digest',
            with: [
                'recipientName' => $this->recipient->first_name,
                'items'         => $this->items,
            ],
        );
    }
}
