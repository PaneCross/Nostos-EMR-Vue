<?php

// ─── UpdateTransportRequestRequest ───────────────────────────────────────────
// Validates dispatcher updates to an existing participant transport
// request : primarily either scheduling a confirmed pickup time or
// cancelling the trip.
//
// Auth gate: Any authenticated user; finer-grained checks are in the
//            controller (typically transportation dispatch).
// Validates: optional status (only "scheduled" or "cancelled" allowed
//            here), optional scheduled_pickup_time (set when dispatcher
//            commits to a slot), optional special_instructions update,
//            and cancellation_reason : required when status = cancelled.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\TransportRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTransportRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            // Transportation team can schedule (set scheduled_pickup_time)
            'status'                => ['sometimes', Rule::in(['scheduled', 'cancelled'])],
            'scheduled_pickup_time' => ['sometimes', 'nullable', 'date'],
            'special_instructions'  => ['sometimes', 'nullable', 'string', 'max:1000'],
            'cancellation_reason'   => ['required_if:status,cancelled', 'nullable', 'string', 'max:500'],
        ];
    }
}
