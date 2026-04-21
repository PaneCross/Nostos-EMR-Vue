<?php

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
