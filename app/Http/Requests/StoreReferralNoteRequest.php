<?php

// ─── StoreReferralNoteRequest ─────────────────────────────────────────────────
// Validates adding a note to an enrollment referral. Write authorization is
// enforced in ReferralNoteController, not here.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReferralNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}
