<?php

// ─── StoreParticipantRequest ─────────────────────────────────────────────────
// Validates enrolling a new PACE participant. PACE = Programs of
// All-Inclusive Care for the Elderly, a Medicare/Medicaid program for
// frail elders aged 55+ who meet nursing-facility level of care. A
// "participant" is an enrolled member; pre-enrollment people are
// "potential enrollees" handled in the Referral flow.
//
// Auth gate: User's department must be "enrollment".
// Validates: site_id, name, dob (must be in the past), optional
//            demographics, optional Medicare/Medicaid/PACE contract IDs,
//            language + interpreter prefs, enrollment_status (enum),
//            optional enrollment_date, optional address. If status is
//            "disenrolled", disenrollment_date and disenrollment_reason
//            are required; disenrollment_type is voluntary | involuntary
//            | death.
// Notable rules: 42 CFR §460.160(b) : death is treated as a disenrollment
//                REASON, not a top-level status.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Support\DisenrollmentTaxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->department === 'enrollment';
    }

    public function rules(): array
    {
        return [
            'site_id'            => ['required', 'integer', 'exists:shared_sites,id'],
            'first_name'         => ['required', 'string', 'max:100'],
            'last_name'          => ['required', 'string', 'max:100'],
            'preferred_name'     => ['nullable', 'string', 'max:100'],
            'dob'                => ['required', 'date', 'before:today'],
            'gender'             => ['nullable', 'string', 'max:20'],
            'pronouns'           => ['nullable', 'string', 'max:30'],
            'ssn_last_four'      => ['nullable', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'medicare_id'        => ['nullable', 'string', 'max:20'],
            'medicaid_id'        => ['nullable', 'string', 'max:20'],
            'pace_contract_id'   => ['nullable', 'string', 'max:20'],
            'h_number'           => ['nullable', 'string', 'max:20'],
            'primary_language'   => ['nullable', 'string', 'max:50'],
            'interpreter_needed' => ['boolean'],
            'interpreter_language'=> ['nullable', 'string', 'max:50'],
            // Per 42 CFR §460.160(b): death is a disenrollment reason, not a top-level status.
            'enrollment_status'  => ['required', 'in:referred,intake,pending,enrolled,disenrolled'],
            'enrollment_date'    => ['nullable', 'date'],
            'disenrollment_date'   => ['nullable', 'date', 'required_if:enrollment_status,disenrolled'],
            'disenrollment_reason' => ['nullable', 'string', 'required_if:enrollment_status,disenrolled', Rule::in(DisenrollmentTaxonomy::REASONS)],
            'disenrollment_type'   => ['nullable', Rule::in(['voluntary', 'involuntary', 'death'])],
            'nursing_facility_eligible' => ['boolean'],

            // Address (optional at creation)
            'address.street'    => ['nullable', 'string', 'max:200'],
            'address.city'      => ['nullable', 'string', 'max:100'],
            'address.state'     => ['nullable', 'string', 'size:2'],
            'address.zip'       => ['nullable', 'string', 'max:10'],
        ];
    }
}
