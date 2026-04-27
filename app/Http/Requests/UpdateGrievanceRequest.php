<?php

// ─── UpdateGrievanceRequest ──────────────────────────────────────────────────
// Validates updates to an in-flight Grievance (a formal participant
// complaint about the PACE program). Used by QA / Compliance staff while
// investigating : to log progress, reassign, or mark CMS-reported.
//
// Auth gate: authorize() returns true; finer-grained checks are in the
//            controller.
// Validates: optional investigation_notes (free text), optional
//            assigned_to_user_id (must exist in shared_users),
//            cms_reportable boolean and cms_reported_at timestamp for
//            tracking CMS quarterly submissions.
// Notable rules: 42 CFR §460.120 : PACE grievance process and the related
//                CMS reporting cadence.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGrievanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'investigation_notes'  => ['nullable', 'string'],
            'assigned_to_user_id'  => ['nullable', 'integer', 'exists:shared_users,id'],
            'cms_reportable'       => ['boolean'],
            'cms_reported_at'      => ['nullable', 'date'],
        ];
    }
}
