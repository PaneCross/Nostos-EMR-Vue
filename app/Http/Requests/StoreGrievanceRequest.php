<?php

// ─── StoreGrievanceRequest ───────────────────────────────────────────────────
// Validates logging a new Grievance — a formal complaint by or on behalf of
// a participant about the PACE program (quality of care, staff behavior,
// access, billing, etc.). Distinct from an Appeal, which specifically
// contests a service-denial decision.
//
// Auth gate: authorize() returns true; finer-grained checks are in the
//            controller (typically QA / Compliance or front-desk intake).
// Validates: participant_id (must exist), filer name + filed_by_type
//            (participant / family / staff / etc.), category (enum),
//            description ≥10 chars, optional priority, optional assignee,
//            cms_reportable flag.
// Notable rules: 42 CFR §460.120 — PACE grievance process. Must be tracked
//                with 30-day aging and resolution documentation; some
//                categories are reportable to CMS.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\Grievance;
use Illuminate\Foundation\Http\FormRequest;

class StoreGrievanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'participant_id'       => ['required', 'integer', 'exists:emr_participants,id'],
            'filed_by_name'        => ['required', 'string', 'max:200'],
            'filed_by_type'        => ['required', 'string', 'in:' . implode(',', Grievance::FILED_BY_TYPES)],
            'filed_at'             => ['nullable', 'date'],
            'category'             => ['required', 'string', 'in:' . implode(',', Grievance::CATEGORIES)],
            'description'          => ['required', 'string', 'min:10'],
            'priority'             => ['nullable', 'string', 'in:standard,urgent'],
            'assigned_to_user_id'  => ['nullable', 'integer', 'exists:shared_users,id'],
            'cms_reportable'       => ['boolean'],
        ];
    }
}
