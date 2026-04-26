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

    /**
     * Phase W3 — domain-aware messages tied to 42 CFR §460.122 (grievance procedures).
     * (Restored in Z7 after Z2 header-pass clobbered it.)
     */
    public function messages(): array
    {
        return [
            'participant_id.required' => 'Select the participant filing this grievance.',
            'filed_by_name.required'  => 'Per §460.122(b), grievances must identify who filed them.',
            'filed_by_type.required'  => 'Indicate filer type: participant, family, caregiver, etc.',
            'category.required'       => 'A grievance category is required for §460.122 reporting.',
            'description.required'    => 'Provide a description of the grievance (minimum 10 characters).',
            'description.min'         => 'Grievance description must be at least 10 characters.',
        ];
    }
}
