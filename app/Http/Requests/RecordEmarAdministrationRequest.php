<?php

// ─── RecordEmarAdministrationRequest ─────────────────────────────────────────
// Validates logging a single medication-pass event on the eMAR. eMAR =
// Electronic Medication Administration Record : the per-dose log of which
// nurse gave (or didn't give) which medication to which participant at
// what time. Each scheduled dose generates an eMAR row; this endpoint
// records the outcome.
//
// Auth gate: Any authenticated user (typically nursing staff during the
//            med pass).
// Validates: status (given/refused/held/not_available/missed). When status
//            is "given", administered_at is required. Any non-given status
//            requires a reason_not_given explanation. Optional witness
//            user (used for controlled substances) and free-text notes.
// Notable rules: Underpins the BCMA (Barcode Medication Administration)
//                workflow; appended-to by the scan-verify endpoint.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\EmarRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordEmarAdministrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'status'           => ['required', Rule::in(['given', 'refused', 'held', 'not_available', 'missed'])],
            'administered_at'  => ['nullable', 'date', 'required_if:status,given'],
            'dose_given'       => ['nullable', 'string', 'max:50'],
            'route_given'      => ['nullable', 'string', 'max:50'],
            'reason_not_given' => ['nullable', 'string', 'max:500',
                                   Rule::requiredIf(fn () => in_array(
                                       $this->input('status'),
                                       ['refused', 'held', 'not_available', 'missed']
                                   ))],
            'witness_user_id'  => ['nullable', 'integer', 'exists:shared_users,id'],
            'notes'            => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Phase W3 : messages emphasize the medication-administration audit trail.
     * (Restored in Z7 after Z2 header-pass clobbered it.)
     */
    public function messages(): array
    {
        return [
            'status.required'             => 'Select an administration status (given, refused, held, not_available, or missed).',
            'status.in'                   => 'Status must be one of: given, refused, held, not_available, missed.',
            'administered_at.required_if' => 'When marking a dose given, the administration time is required for the eMAR audit trail.',
            'reason_not_given.required'   => 'Per medication-administration policy, doses not given (refused/held/not_available/missed) must include a reason.',
            'witness_user_id.exists'      => 'Witness user not found. Controlled substances often require a witness signature.',
        ];
    }
}
