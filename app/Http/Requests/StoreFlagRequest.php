<?php

// ─── StoreFlagRequest ────────────────────────────────────────────────────────
// Validates attaching a clinical or safety flag to a participant. Flags are
// short, high-visibility tags shown on the participant header so any staff
// member opening the chart immediately sees critical context (e.g. DNR =
// Do Not Resuscitate, fall risk, oxygen-dependent, behavioral concerns,
// elopement risk, hospice).
//
// Auth gate: Any authenticated user; finer-grained checks are in the
//            controller.
// Validates: flag_type (one of a fixed list including wheelchair, oxygen,
//            dnr, fall_risk, hospice, etc.), optional description, and a
//            required severity (low/medium/high/critical) which drives
//            color and placement on the header.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\ParticipantFlag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'flag_type'   => ['required', Rule::in(array_column(
                array_map(fn ($t) => ['v' => $t], [
                    'wheelchair', 'stretcher', 'oxygen', 'behavioral',
                    'fall_risk', 'wandering_risk', 'isolation', 'dnr',
                    'weight_bearing_restriction', 'dietary_restriction',
                    'elopement_risk', 'hospice', 'other',
                ]), 'v'
            ))],
            'description' => ['nullable', 'string', 'max:500'],
            'severity'    => ['required', 'in:low,medium,high,critical'],
        ];
    }
}
