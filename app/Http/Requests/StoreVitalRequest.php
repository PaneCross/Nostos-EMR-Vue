<?php

// ─── StoreVitalRequest ────────────────────────────────────────────────────────
// Validates new vital sign recordings. All measurement fields are nullable
// since not all vitals are captured at every encounter.
// Ranges are clinical bounds for the elderly PACE population.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVitalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'recorded_at'       => ['nullable', 'date', 'before_or_equal:now'],
            'bp_systolic'       => ['nullable', 'integer', 'min:40', 'max:300'],
            'bp_diastolic'      => ['nullable', 'integer', 'min:20', 'max:200'],
            'pulse'             => ['nullable', 'integer', 'min:20', 'max:300'],
            'respiratory_rate'  => ['nullable', 'integer', 'min:4', 'max:60'],
            'temperature_f'     => ['nullable', 'numeric', 'min:85', 'max:115'],
            'o2_saturation'     => ['nullable', 'integer', 'min:0', 'max:100'],
            'weight_lbs'        => ['nullable', 'numeric', 'min:40', 'max:600'],
            'height_in'         => ['nullable', 'numeric', 'min:36', 'max:96'],
            'pain_score'        => ['nullable', 'integer', 'min:0', 'max:10'],
            'blood_glucose'        => ['nullable', 'integer', 'min:20', 'max:600'],
            'blood_glucose_timing' => ['nullable', 'in:fasting,post_meal_2h,random,pre_meal'],
            'position'             => ['nullable', 'in:sitting,standing,lying'],
            'notes'             => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Phase Y2 (Audit-13 polish): clinical-context messages.
     * Generic "must be between X and Y" tells the user nothing about why a
     * value is rejected; clinical-bound rules are tightened to elderly-PACE
     * physiology and the messages explain that.
     */
    public function messages(): array
    {
        return [
            'recorded_at.before_or_equal' => 'Recorded time cannot be in the future.',
            'bp_systolic.min'             => 'Systolic BP outside plausible range (40-300 mmHg). Verify reading.',
            'bp_systolic.max'             => 'Systolic BP outside plausible range (40-300 mmHg). Verify reading.',
            'bp_diastolic.min'            => 'Diastolic BP outside plausible range (20-200 mmHg). Verify reading.',
            'bp_diastolic.max'            => 'Diastolic BP outside plausible range (20-200 mmHg). Verify reading.',
            'pulse.min'                   => 'Pulse outside plausible range (20-300 bpm).',
            'pulse.max'                   => 'Pulse outside plausible range (20-300 bpm).',
            'temperature_f.min'           => 'Temperature outside plausible range (85-115 °F).',
            'temperature_f.max'           => 'Temperature outside plausible range (85-115 °F).',
            'o2_saturation.max'           => 'O2 saturation cannot exceed 100%.',
            'pain_score.max'              => 'Pain score is on a 0-10 scale.',
            'blood_glucose.min'           => 'Blood glucose outside plausible range (20-600 mg/dL).',
            'blood_glucose.max'           => 'Blood glucose outside plausible range (20-600 mg/dL).',
        ];
    }
}
