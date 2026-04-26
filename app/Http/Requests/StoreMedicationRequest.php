<?php

namespace App\Http\Requests;

use App\Models\Medication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMedicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated clinician can add medications (dept check in controller)
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'drug_name'                    => ['required', 'string', 'max:200'],
            'rxnorm_code'                  => ['nullable', 'string', 'max:20'],
            'dose'                         => ['nullable', 'numeric', 'min:0'],
            'dose_unit'                    => ['nullable', Rule::in(Medication::DOSE_UNITS)],
            'route'                        => ['nullable', Rule::in(Medication::ROUTES)],
            'frequency'                    => ['nullable', Rule::in(Medication::FREQUENCIES)],
            'is_prn'                       => ['boolean'],
            'prn_indication'               => ['nullable', 'string', 'max:300', 'required_if:is_prn,true'],
            'prescribing_provider_user_id' => ['nullable', 'integer', 'exists:shared_users,id'],
            'prescribed_date'              => ['nullable', 'date'],
            'start_date'                   => ['required', 'date'],
            'end_date'                     => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_controlled'                => ['boolean'],
            'controlled_schedule'          => ['nullable', Rule::in(['II', 'III', 'IV', 'V'])],
            'refills_remaining'            => ['nullable', 'integer', 'min:0'],
            'pharmacy_notes'               => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Phase Y2 (Audit-13 polish): pharmacy-aware error text.
     * Generic "in" rule errors don't tell the prescriber what set is allowed;
     * these messages name the constraint set explicitly.
     */
    public function messages(): array
    {
        return [
            'drug_name.required'                  => 'Drug name is required (use the search to pull an RxNorm-coded entry).',
            'dose_unit.in'                        => 'Dose unit must be one of: ' . implode(', ', \App\Models\Medication::DOSE_UNITS) . '.',
            'route.in'                            => 'Route must be one of: ' . implode(', ', \App\Models\Medication::ROUTES) . '.',
            'frequency.in'                        => 'Frequency must be one of: ' . implode(', ', \App\Models\Medication::FREQUENCIES) . '.',
            'prn_indication.required_if'          => 'PRN medications require a documented indication ("for what").',
            'end_date.after_or_equal'             => 'End date cannot be earlier than start date.',
            'controlled_schedule.in'              => 'Controlled schedule must be II, III, IV, or V (DEA classification).',
        ];
    }
}
