<?php

namespace App\Http\Requests;

use App\Models\Appeal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) return false;
        return $user->isSuperAdmin()
            || in_array($user->department, ['qa_compliance', 'enrollment', 'it_admin'], true)
            || (method_exists($user, 'hasDesignation') && (
                $user->hasDesignation('medical_director') || $user->hasDesignation('compliance_officer')
            ));
    }

    public function rules(): array
    {
        return [
            'outcome'   => ['required', Rule::in(Appeal::DECIDED_STATUSES)],
            'narrative' => ['required', 'string', 'min:10', 'max:8000'],
        ];
    }
}
