<?php

// ─── StoreMedReconciliationRequest ───────────────────────────────────────────
// Validates recording a Medication Reconciliation event : the clinical
// process where a clinician compares the participant's current med list
// against another source (hospital discharge summary, IDT visit, primary
// care visit) and decides for each med whether to continue, discontinue,
// modify, or add new. IDT = Interdisciplinary Team, the PACE clinical
// team that meets to plan each member's care.
//
// Auth gate: Any authenticated user; finer-grained checks are in the
//            controller (typically pharmacy or clinical staff).
// Validates: reconciliation_type, reconciled_at timestamp, optional
//            clinical_notes + has_discrepancies flag, plus a required
//            array of reconciled_medications : each row needs an existing
//            medication_id, a drug_name, an action enum (continue /
//            discontinue / modify / new), and optional discrepancy_note.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\MedReconciliation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMedReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'reconciliation_type'                => ['required', Rule::in(MedReconciliation::TYPES)],
            'reconciled_at'                      => ['required', 'date'],
            'clinical_notes'                     => ['nullable', 'string', 'max:5000'],
            'has_discrepancies'                  => ['boolean'],
            // Array of medication reconciliation entries
            'reconciled_medications'             => ['required', 'array'],
            'reconciled_medications.*.medication_id'  => ['required', 'integer', 'exists:emr_medications,id'],
            'reconciled_medications.*.drug_name'      => ['required', 'string', 'max:200'],
            'reconciled_medications.*.action'         => ['required', Rule::in(['continue', 'discontinue', 'modify', 'new'])],
            'reconciled_medications.*.discrepancy_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
