<?php

// ─── StoreConsentRequest ─────────────────────────────────────────────────────
// Validates capturing a signed consent document on a participant's chart
// (enrollment agreement, HIPAA Notice of Privacy Practices acknowledgement,
// procedure consent, photo/media release, etc.).
//
// Auth gate: authorize() returns true; finer-grained checks are in the
//            controller (typically enrollment, social work, or clinical
//            staff).
// Validates: consent_type and status (enums on ConsentRecord),
//            document_title, optional document_version, optional
//            acknowledged_by + acknowledged_at, optional
//            representative_type when a legal rep signs (POA, guardian),
//            optional expiration_date.
// Notable rules: HIPAA §164.508 (authorization for use/disclosure of PHI)
//                and 42 CFR §460.156 (PACE enrollment agreement consent).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\ConsentRecord;
use Illuminate\Foundation\Http\FormRequest;

class StoreConsentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'consent_type'        => ['required', 'string', 'in:' . implode(',', ConsentRecord::CONSENT_TYPES)],
            'document_title'      => ['required', 'string', 'max:300'],
            'document_version'    => ['nullable', 'string', 'max:50'],
            'status'              => ['required', 'string', 'in:' . implode(',', ConsentRecord::STATUSES)],
            'acknowledged_by'     => ['nullable', 'string', 'max:200'],
            'acknowledged_at'     => ['nullable', 'date'],
            'representative_type' => ['nullable', 'string', 'in:' . implode(',', ConsentRecord::REPRESENTATIVE_TYPES)],
            'expiration_date'     => ['nullable', 'date'],
            'notes'               => ['nullable', 'string'],
        ];
    }
}
