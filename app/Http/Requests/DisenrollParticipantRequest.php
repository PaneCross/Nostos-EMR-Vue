<?php

// ─── DisenrollParticipantRequest ──────────────────────────────────────────────
// Validates the disenrollment payload.
// cms_notification_required = true triggers a HPMS reporting flag (Phase 6B: QA task).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Support\DisenrollmentTaxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Canonical CMS reasons only (42 CFR §460.160-164).
            // See App\Support\DisenrollmentTaxonomy + feedback_pace_disenrollment_taxonomy.md.
            'reason'                   => ['required', 'string', Rule::in(DisenrollmentTaxonomy::REASONS)],
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
