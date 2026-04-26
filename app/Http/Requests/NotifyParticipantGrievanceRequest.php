<?php

// ─── NotifyParticipantGrievanceRequest ───────────────────────────────────────
// Validates logging the act of notifying a participant about their
// Grievance (acknowledgement of receipt, status update, or final
// resolution letter). PACE plans are required to communicate grievance
// progress and outcomes back to the complainant; this endpoint records
// HOW that communication happened.
//
// Auth gate: authorize() returns true; finer-grained checks are in the
//            controller.
// Validates: notification_method — must be one of
//            Grievance::NOTIFICATION_METHODS (mail, phone, in-person,
//            secure-message, etc.).
// Notable rules: 42 CFR §460.120 — PACE grievance process requires written
//                communication of resolution to the participant.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\Grievance;
use Illuminate\Foundation\Http\FormRequest;

class NotifyParticipantGrievanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'notification_method' => [
                'required', 'string',
                'in:' . implode(',', Grievance::NOTIFICATION_METHODS),
            ],
        ];
    }
}
