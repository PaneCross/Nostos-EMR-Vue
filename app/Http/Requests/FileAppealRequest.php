<?php

// ─── FileAppealRequest ───────────────────────────────────────────────────────
// Validates filing a new Appeal against a previously-issued Service Denial
// Notice (the participant-facing letter sent when a service request is
// denied). Staff file on behalf of a participant; direct participant
// portal filing is a separate endpoint not in MVP.
//
// Auth gate: Any authenticated user; finer-grained checks are in the
//            controller.
// Validates: service_denial_notice_id (must exist), type and filed_by
//            (enums on Appeal model), optional filer name + reason, and
//            continuation_of_benefits — whether services keep flowing while
//            the appeal is pending.
// Notable rules: 42 CFR §460.122 — appellants generally have 60 days to
//                file from the date of the denial notice.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\Appeal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FileAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated staff may file on behalf of a participant. Participants
        // filing directly goes through a separate portal endpoint (not in MVP).
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'service_denial_notice_id' => ['required', 'integer', 'exists:emr_service_denial_notices,id'],
            'type'                     => ['required', Rule::in(Appeal::TYPES)],
            'filed_by'                 => ['required', Rule::in(Appeal::FILED_BY_VALUES)],
            'filed_by_name'            => ['nullable', 'string', 'max:200'],
            'filing_reason'            => ['nullable', 'string', 'max:4000'],
            'continuation_of_benefits' => ['required', 'boolean'],
        ];
    }
}
