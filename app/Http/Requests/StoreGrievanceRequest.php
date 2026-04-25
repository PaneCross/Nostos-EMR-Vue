<?php

namespace App\Http\Requests;

use App\Models\Grievance;
use Illuminate\Foundation\Http\FormRequest;

class StoreGrievanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'participant_id'       => ['required', 'integer', 'exists:emr_participants,id'],
            'filed_by_name'        => ['required', 'string', 'max:200'],
            'filed_by_type'        => ['required', 'string', 'in:' . implode(',', Grievance::FILED_BY_TYPES)],
            'filed_at'             => ['nullable', 'date'],
            'category'             => ['required', 'string', 'in:' . implode(',', Grievance::CATEGORIES)],
            'description'          => ['required', 'string', 'min:10'],
            'priority'             => ['nullable', 'string', 'in:standard,urgent'],
            'assigned_to_user_id'  => ['nullable', 'integer', 'exists:shared_users,id'],
            'cms_reportable'       => ['boolean'],
        ];
    }

    /**
     * Phase W3 — domain-aware messages tied to 42 CFR §460.122 (grievance procedures).
     */
    public function messages(): array
    {
        return [
            'participant_id.required' => 'Select the participant filing this grievance.',
            'filed_by_name.required'  => 'Per §460.122(b), grievances must identify who filed them.',
            'filed_by_type.required'  => 'Indicate filer type: participant, family, caregiver, etc.',
            'category.required'       => 'A grievance category is required for §460.122 reporting.',
            'description.required'    => 'Provide a description of the grievance (minimum 10 characters).',
            'description.min'         => 'Grievance description must be at least 10 characters.',
        ];
    }
}
