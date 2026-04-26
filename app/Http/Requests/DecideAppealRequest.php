<?php

// ─── DecideAppealRequest ─────────────────────────────────────────────────────
// Validates the final ruling on a participant Appeal (uphold or overturn the
// original service denial). PACE = Programs of All-Inclusive Care for the
// Elderly; appeals contest a service-denial decision made by the plan.
//
// Auth gate: Super-admin, or department in {qa_compliance, enrollment,
//            it_admin}, or a user holding the medical_director or
//            compliance_officer designation.
// Validates: outcome (must be one of Appeal::DECIDED_STATUSES) + narrative
//            (10–8000 chars) explaining the rationale for the ruling.
// Notable rules: 42 CFR §460.122 — PACE service-denial / appeal regulation.
//                Standard appeals must be decided within 30 days; expedited
//                within 72 hours.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\Appeal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) return false;
        return $user->isSuperAdmin()
            || in_array($user->department, ['qa_compliance', 'enrollment', 'it_admin'], true)
            || (method_exists($user, 'hasDesignation') && (
                $user->hasDesignation('medical_director') || $user->hasDesignation('compliance_officer')
            ));
    }

    public function rules(): array
    {
        return [
            'outcome'   => ['required', Rule::in(Appeal::DECIDED_STATUSES)],
            'narrative' => ['required', 'string', 'min:10', 'max:8000'],
        ];
    }
}
