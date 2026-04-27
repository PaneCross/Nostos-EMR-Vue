<?php

// ─── StoreClinicalNoteRequest ─────────────────────────────────────────────────
// Validates creating a new clinical note (always starts as a draft).
// late_entry_reason is required when is_late_entry is true.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\ClinicalNote;
use Illuminate\Foundation\Http\FormRequest;

class StoreClinicalNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'note_type'          => ['required', 'in:' . implode(',', ClinicalNote::NOTE_TYPES)],
            'visit_type'         => ['required', 'in:in_center,home_visit,telehealth,phone'],
            'visit_date'         => ['required', 'date', 'before_or_equal:today'],
            'visit_time'         => ['nullable', 'date_format:H:i'],
            'department'         => ['required', 'string', 'max:30'],

            // SOAP fields (nullable : only populated for soap note_type)
            'subjective'         => ['nullable', 'string', 'max:10000'],
            'objective'          => ['nullable', 'string', 'max:10000'],
            'assessment'         => ['nullable', 'string', 'max:10000'],
            'plan'               => ['nullable', 'string', 'max:10000'],

            // Structured content for non-SOAP templates
            'content'            => ['nullable', 'array'],

            // Late entry compliance
            'is_late_entry'      => ['boolean'],
            'late_entry_reason'  => ['required_if:is_late_entry,true', 'nullable', 'string', 'max:500'],

            // Phase B7 : optional template link + problem linkage
            'note_template_id'      => ['nullable', 'integer', 'exists:emr_note_templates,id'],
            'primary_problem_id'    => ['nullable', 'integer', 'exists:emr_problems,id'],
            'secondary_problem_ids' => ['nullable', 'array'],
            'secondary_problem_ids.*' => ['integer', 'exists:emr_problems,id'],
        ];
    }

    /**
     * Phase W3 : CFR-aware validation messages for user-visible 422 surfaces.
     */
    public function messages(): array
    {
        return [
            'note_type.required'         => 'Select a note type before saving.',
            'note_type.in'               => 'That note type is not allowed. Choose from: ' . implode(', ', \App\Models\ClinicalNote::NOTE_TYPES) . '.',
            'visit_type.required'        => 'Visit type is required (in_center, home_visit, telehealth, or phone).',
            'visit_date.required'        => 'Visit date is required.',
            'visit_date.before_or_equal' => 'Visit date cannot be in the future.',
            'department.required'        => 'Author department is required for chart attribution.',
            'late_entry_reason.required_if' => 'Per 42 CFR §460.210, late chart entries require a documented reason explaining the delay.',
            'note_template_id.exists'    => 'Note template not found or no longer available.',
            'primary_problem_id.exists'  => 'Linked problem not found on this participant\'s problem list.',
        ];
    }
}
