<?php

// ─── SubmitRcaRequest ─────────────────────────────────────────────────────────
// Validates the RCA submission payload.
// QA compliance and clinical dept admins may submit RCAs.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitRcaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->department, [
            'qa_compliance', 'primary_care', 'it_admin',
        ], true);
    }

    public function rules(): array
    {
        return [
            'rca_text' => ['required', 'string', 'min:50', 'max:20000'],
        ];
    }

    /**
     * Phase W3 : Sentinel-event RCA per Phase B3: 30-day deadline (42 CFR §460.136
     * QAPI), so message wording cites the regulatory expectation.
     */
    public function messages(): array
    {
        return [
            'rca_text.required' => 'Per 42 CFR §460.136 QAPI, sentinel-event Root Cause Analyses require documented narrative.',
            'rca_text.min'      => 'RCA must be at least 50 characters describing the underlying cause(s); CMS auditors reject thin RCAs.',
            'rca_text.max'      => 'RCA text exceeds 20,000 characters. Attach supporting documents separately.',
        ];
    }

    protected function failedAuthorization(): never
    {
        abort(403, 'Only QA Compliance, Primary Care, or IT Admin may submit an RCA.');
    }
}
