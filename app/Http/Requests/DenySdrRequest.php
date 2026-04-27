<?php

// ─── DenySdrRequest ──────────────────────────────────────────────────────────
// Validates the issuance of a formal denial on a Service Delivery Request
// (SDR). An SDR is an internal hand-off where one PACE department asks
// another to deliver a service for a participant (e.g. PCP asks pharmacy
// to fill a prescription). Denying an SDR generates a participant-facing
// Service Denial Notice that may then be appealed.
//
// Auth gate: Super-admin, or department in {qa_compliance, enrollment,
//            it_admin}, or a user with the medical_director designation.
// Validates: reason_code (≤80 chars), reason_narrative (≤4000 chars), and an
//            optional delivery_method describing how the notice will reach
//            the participant (mail, hand-delivered, etc.).
// Notable rules: 42 CFR §460.121 : 72-hour CMS clock for standard SDRs;
//                §460.122 governs the denial-notice + appeal pathway.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\ServiceDenialNotice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DenySdrRequest extends FormRequest
{
    public function authorize(): bool
    {
        // QA, enrollment, IT admin, or medical_director designation may issue denials
        $user = $this->user();
        if (! $user) return false;
        return $user->isSuperAdmin()
            || in_array($user->department, ['qa_compliance', 'enrollment', 'it_admin'], true)
            || (method_exists($user, 'hasDesignation') && $user->hasDesignation('medical_director'));
    }

    public function rules(): array
    {
        return [
            'reason_code'      => ['required', 'string', 'max:80'],
            'reason_narrative' => ['required', 'string', 'max:4000'],
            'delivery_method'  => ['nullable', Rule::in(ServiceDenialNotice::DELIVERY_METHODS)],
        ];
    }
}
