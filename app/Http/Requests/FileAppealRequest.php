<?php

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

    /**
     * Phase W3 — messages cite 42 CFR §460.122 (appeals workflow) so users see
     * regulatory context, not generic Laravel defaults.
     */
    public function messages(): array
    {
        return [
            'service_denial_notice_id.required' => 'An appeal must reference the service denial notice it appeals (§460.122(a)).',
            'service_denial_notice_id.exists'   => 'That service denial notice was not found.',
            'type.required'                     => 'Select appeal type: standard (30-day) or expedited (72-hour) per §460.122.',
            'filed_by.required'                 => 'Identify who filed the appeal (participant, representative, provider).',
            'continuation_of_benefits.required' => 'Per §460.122(d), specify whether the participant elects continuation of benefits during the appeal.',
        ];
    }
}
