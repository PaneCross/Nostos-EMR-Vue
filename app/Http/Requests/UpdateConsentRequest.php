<?php

// ─── UpdateConsentRequest ────────────────────────────────────────────────────
// Validates editing an existing consent record on a participant's chart —
// typically used to flip status (active → revoked, pending → active),
// attach acknowledgement details, or upload the scanned signed copy.
//
// Auth gate: authorize() returns true; finer-grained checks are in the
//            controller.
// Validates: optional status + acknowledged_by + acknowledged_at,
//            optional representative_type (POA = Power of Attorney,
//            healthcare proxy, guardian, etc. — used when someone other
//            than the participant signs), optional notes, optional
//            document_path pointing at the stored scan.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\ConsentRecord;
use Illuminate\Foundation\Http\FormRequest;

class UpdateConsentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'status'              => ['sometimes', 'required', 'string', 'in:' . implode(',', ConsentRecord::STATUSES)],
            'acknowledged_by'     => ['nullable', 'string', 'max:200'],
            'acknowledged_at'     => ['nullable', 'date'],
            'representative_type' => ['nullable', 'string', 'in:' . implode(',', ConsentRecord::REPRESENTATIVE_TYPES)],
            'notes'               => ['nullable', 'string'],
            'document_path'       => ['nullable', 'string'],
        ];
    }
}
