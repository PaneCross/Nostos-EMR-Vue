<?php

// ─── UpdateLocationRequest ────────────────────────────────────────────────────
// All fields are optional for partial updates (PATCH-friendly).
// Duplicate detection skips the record being updated (so you can fix typos
// without tripping over yourself).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'location_type' => ['sometimes', Rule::in(Location::LOCATION_TYPES)],
            'name'          => ['sometimes', 'string', 'max:150'],
            'label'         => ['nullable', 'string', 'max:100'],
            'street'        => ['nullable', 'string', 'max:255'],
            'apartment'     => ['nullable', 'string', 'max:30'],
            'suite'         => ['nullable', 'string', 'max:30'],
            'building'      => ['nullable', 'string', 'max:100'],
            'floor'         => ['nullable', 'string', 'max:30'],
            'unit'          => ['nullable', 'string', 'max:50'],
            'city'          => ['nullable', 'string', 'max:100'],
            'state'         => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'zip'           => ['nullable', 'string', 'regex:/^\d{5}(-\d{4})?$/'],
            'phone'         => ['nullable', 'string', 'max:20'],
            'contact_name'  => ['nullable', 'string', 'max:150'],
            'notes'         => ['nullable', 'string', 'max:2000'],
            'access_notes'  => ['nullable', 'string', 'max:500'],
            'site_id'       => ['nullable', 'integer', 'exists:shared_sites,id'],
            'is_active'     => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'state.regex' => 'State must be a 2-letter code (e.g. CA, TX).',
            'zip.regex'   => 'ZIP must be 5 digits or ZIP+4 format (e.g. 94110 or 94110-1234).',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            if ($v->errors()->isNotEmpty()) return;
            $street = trim((string) $this->input('street', ''));
            $city   = trim((string) $this->input('city', ''));
            if ($street === '' || $city === '') return;

            // Exclude the current record (route-model-bound as `location`)
            $currentId = $this->route('location')?->id;
            $tenantId  = auth()->user()->tenant_id;

            $match = Location::where('tenant_id', $tenantId)
                ->where('id', '!=', $currentId)
                ->whereRaw('LOWER(street) = LOWER(?)', [$street])
                ->whereRaw('LOWER(city) = LOWER(?)', [$city])
                ->first();

            if ($match) {
                $v->errors()->add(
                    'street',
                    "Another location at this address already exists: \"{$match->name}\" (ID #{$match->id}). Add a unique identifier (apt/suite/building) to differentiate."
                );
            }
        });
    }
}
