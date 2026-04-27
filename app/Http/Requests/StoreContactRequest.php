<?php

// ─── StoreContactRequest ─────────────────────────────────────────────────────
// Validates adding a contact person to a participant's chart : emergency
// contact, next of kin, POA (Power of Attorney : a legal representative),
// caregiver, primary-care or specialist physician outside PACE, etc.
//
// Auth gate: Any authenticated user; finer-grained checks are in the
//            controller.
// Validates: contact_type (enum), first/last name (required), optional
//            relationship + phones + email, boolean flags for
//            is_legal_representative and is_emergency_contact, and a
//            priority_order (1–99) used to rank contacts on the
//            participant header.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'contact_type'            => ['required', 'in:emergency,next_of_kin,poa,caregiver,pcp,specialist,other'],
            'first_name'              => ['required', 'string', 'max:100'],
            'last_name'               => ['required', 'string', 'max:100'],
            'relationship'            => ['nullable', 'string', 'max:100'],
            'phone_primary'           => ['nullable', 'string', 'max:20'],
            'phone_secondary'         => ['nullable', 'string', 'max:20'],
            'email'                   => ['nullable', 'email', 'max:150'],
            'is_legal_representative' => ['boolean'],
            'is_emergency_contact'    => ['boolean'],
            'priority_order'          => ['integer', 'min:1', 'max:99'],
            'notes'                   => ['nullable', 'string', 'max:1000'],
        ];
    }
}
