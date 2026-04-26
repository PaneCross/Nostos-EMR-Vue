<?php

// ─── ResolveGrievanceRequest ─────────────────────────────────────────────────
// Validates closing out a Grievance — a formal participant complaint about
// the PACE program (quality of care, staff conduct, billing, etc.).
// Distinct from an Appeal, which contests a specific service denial.
//
// Auth gate: authorize() returns true; finer-grained checks are in the
//            controller (typically QA / Compliance staff).
// Validates: resolution_text (≥10 chars describing how the issue was
//            resolved) and resolution_date.
// Notable rules: 42 CFR §460.120 — PACE grievance process. Resolution must
//                be documented and communicated to the complainant.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveGrievanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'resolution_text' => ['required', 'string', 'min:10'],
            'resolution_date' => ['required', 'date'],
        ];
    }
}
