<?php

// ─── EscalateGrievanceRequest ────────────────────────────────────────────────
// Validates escalating an existing Grievance (a formal participant
// complaint) to a higher level of review — typically from a front-line
// reviewer up to QA / Compliance leadership when the issue is
// CMS-reportable, urgent, or has stalled.
//
// Auth gate: authorize() returns true; finer-grained checks are in the
//            controller.
// Validates: escalation_reason (≥10 chars explaining why escalation is
//            warranted) and an optional escalated_to_user_id. Tenant
//            isolation on the assignee is verified in the controller,
//            not here.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EscalateGrievanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'escalation_reason'    => ['required', 'string', 'min:10'],
            // Optional: specific staff member to assign the escalation to.
            // Must be a valid user in shared_users. Validated in controller for tenant isolation.
            'escalated_to_user_id' => ['nullable', 'integer'],
        ];
    }
}
