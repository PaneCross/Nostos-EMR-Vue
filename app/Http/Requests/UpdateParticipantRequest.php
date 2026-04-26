<?php

// ─── UpdateParticipantRequest ────────────────────────────────────────────────
// Validates edits to an existing PACE participant record. Field-level RBAC
// (Role-Based Access Control) is applied: every authenticated user can
// edit a "base" set (preferences, language, advance directives,
// demographics for SDOH/clinical use), but enrollment-sensitive fields
// (legal name, dob, Medicare/Medicaid IDs, enrollment_status,
// disenrollment fields) are gated to enrollment + it_admin.
//
// Auth gate: Any authenticated user; per-field gating happens inside
//            rules() based on the user's department.
// Validates: base set always includes preferred_name, languages,
//            interpreter flags, advance-directive fields, race/ethnicity,
//            marital status, legal-rep, religion, veteran status,
//            education. Enrollment-only set adds site, legal name, dob,
//            payer IDs, enrollment_status + the disenrollment trio.
// Notable rules: 42 CFR §460.96 (advance directives) and §460.160(b)
//                (death is a disenrollment reason, not a status).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Support\DisenrollmentTaxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        // Field-level RBAC: what each department may update
        $dept = $this->user()->department;

        $base = [
            'preferred_name'      => ['nullable', 'string', 'max:100'],
            'primary_language'    => ['nullable', 'string', 'max:50'],
            'interpreter_needed'  => ['boolean'],
            'interpreter_language'=> ['nullable', 'string', 'max:50'],
            // Advance directive fields (42 CFR 460.96 — writable by clinical depts)
            'advance_directive_status' => ['nullable', 'in:has_directive,declined_directive,incapacitated_no_directive,unknown'],
            'advance_directive_type'   => ['nullable', 'in:dnr,polst,living_will,healthcare_proxy,combined'],
            'advance_directive_reviewed_at' => ['nullable', 'date'],
            // W4-3: Demographics — all depts may update (SDOH, enrollment, clinical use)
            'race'             => ['nullable', 'in:white,black_african_american,asian,american_indian_alaska_native,native_hawaiian_pacific_islander,multiracial,other,unknown,declined'],
            'ethnicity'        => ['nullable', 'in:hispanic_latino,not_hispanic_latino,unknown,declined'],
            'race_detail'      => ['nullable', 'string', 'max:255'],
            'marital_status'   => ['nullable', 'in:single,married,domestic_partner,divorced,widowed,separated,unknown'],
            'legal_representative_type'       => ['nullable', 'in:self,legal_guardian,durable_poa,healthcare_proxy,court_appointed,other'],
            'legal_representative_contact_id' => ['nullable', 'integer', 'exists:emr_participant_contacts,id'],
            'religion'         => ['nullable', 'string', 'max:100'],
            'veteran_status'   => ['nullable', 'in:not_veteran,veteran_active,veteran_inactive,unknown'],
            'education_level'  => ['nullable', 'in:less_than_high_school,high_school_ged,some_college,associates,bachelors,graduate,unknown'],
        ];

        $enrollmentFields = [
            'site_id'            => ['integer', 'exists:shared_sites,id'],
            'first_name'         => ['string', 'max:100'],
            'last_name'          => ['string', 'max:100'],
            'dob'                => ['date', 'before:today'],
            'gender'             => ['nullable', 'string', 'max:20'],
            'pronouns'           => ['nullable', 'string', 'max:30'],
            'ssn_last_four'      => ['nullable', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'medicare_id'        => ['nullable', 'string', 'max:20'],
            'medicaid_id'        => ['nullable', 'string', 'max:20'],
            'pace_contract_id'   => ['nullable', 'string', 'max:20'],
            'h_number'           => ['nullable', 'string', 'max:20'],
            // Per 42 CFR §460.160(b): death is a disenrollment reason, not a status.
            'enrollment_status'    => ['in:referred,intake,pending,enrolled,disenrolled'],
            'enrollment_date'      => ['nullable', 'date'],
            'disenrollment_date'   => ['nullable', 'date', 'required_if:enrollment_status,disenrolled'],
            'disenrollment_reason' => ['nullable', 'string', 'required_if:enrollment_status,disenrolled', Rule::in(DisenrollmentTaxonomy::REASONS)],
            'disenrollment_type'   => ['nullable', Rule::in(DisenrollmentTaxonomy::TYPES)],
            'nursing_facility_eligible' => ['boolean'],
            'nf_certification_date'     => ['nullable', 'date'],
        ];

        return match (true) {
            in_array($dept, ['enrollment', 'it_admin']) => array_merge($base, $enrollmentFields),
            default => $base,
        };
    }
}
