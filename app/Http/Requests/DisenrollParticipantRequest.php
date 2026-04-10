<?php

// ─── DisenrollParticipantRequest ──────────────────────────────────────────────
// Validates the disenrollment payload.
// cms_notification_required = true triggers a HPMS reporting flag (Phase 6B: QA task).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DisenrollParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Enrollment admin, IT admin, and super_admin may disenroll participants
        $dept = $this->user()?->department;
        $role = $this->user()?->role;
        return in_array($dept, ['enrollment', 'it_admin'], true) ||
               ($dept === 'enrollment' && $role === 'admin') ||
               $role === 'super_admin';
    }

    public function rules(): array
    {
        return [
            'reason'                   => ['required', 'string', 'in:voluntary,involuntary,deceased,moved,nf_admission,other'],
            'effective_date'           => ['required', 'date'],
            'notes'                    => ['nullable', 'string', 'max:5000'],
            'cms_notification_required'=> ['required', 'boolean'],
        ];
    }

    protected function failedAuthorization(): never
    {
        abort(403, 'Only Enrollment Admin, IT Admin, or Super Admin may disenroll participants.');
    }
}
