<?php

// ─── StoreTransportRequestRequest ────────────────────────────────────────────
// Validates booking a transport leg for a participant. PACE plans are
// responsible for transporting members to and from the day center,
// medical appointments, and home; transportation is one of the required
// covered services and one of the most operationally complex.
//
// Auth gate: Any authenticated user; finer-grained checks are in the
//            controller (typically transportation dispatch staff).
// Validates: participant_id, trip_type (enum on TransportRequest model —
//            e.g. day-center, medical-appointment, home-return),
//            pickup + dropoff location IDs (must exist in emr_locations),
//            requested_pickup_time (date/time), optional appointment_id
//            link, optional special_instructions (mobility aids,
//            behavioral notes).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\TransportRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransportRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'participant_id'        => ['required', 'integer', 'exists:emr_participants,id'],
            'trip_type'             => ['required', Rule::in(TransportRequest::TRIP_TYPES)],
            'pickup_location_id'    => ['required', 'integer', 'exists:emr_locations,id'],
            'dropoff_location_id'   => ['required', 'integer', 'exists:emr_locations,id'],
            'requested_pickup_time' => ['required', 'date'],
            'appointment_id'        => ['nullable', 'integer', 'exists:emr_appointments,id'],
            'special_instructions'  => ['nullable', 'string', 'max:1000'],
        ];
    }
}
