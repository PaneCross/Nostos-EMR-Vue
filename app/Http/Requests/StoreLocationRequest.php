<?php

// ─── StoreLocationRequest ─────────────────────────────────────────────────────
// Validates new location creation. Open to any authenticated user; only
// deactivation (destroy) is restricted to the Transportation Team.
//
// Includes duplicate prevention: a location at the same (tenant, street, city)
// case-insensitive combination cannot be created twice. Returns a 422 with a
// descriptive error pointing at the existing record.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'location_type' => ['required', Rule::in(Location::LOCATION_TYPES)],
            'name'          => ['required', 'string', 'max:150'],
            'label'         => ['nullable', 'string', 'max:100'],
            // Address (all optional to support virtual locations)
            'street'        => ['nullable', 'string', 'max:255'],
            'apartment'     => ['nullable', 'string', 'max:30'],
            'suite'         => ['nullable', 'string', 'max:30'],
            'building'      => ['nullable', 'string', 'max:100'],
            'floor'         => ['nullable', 'string', 'max:30'],
            'unit'          => ['nullable', 'string', 'max:50'],  // legacy
            'city'          => ['nullable', 'string', 'max:100'],
            'state'         => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'zip'           => ['nullable', 'string', 'regex:/^\d{5}(-\d{4})?$/'],
            // Contact + meta
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

    /**
     * Check for duplicate locations after standard validation passes.
     * Duplicate = same tenant + case-insensitive street + case-insensitive city.
     * Skipped for virtual locations (no street).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            if ($v->errors()->isNotEmpty()) return;
            $street = trim((string) $this->input('street', ''));
            $city   = trim((string) $this->input('city', ''));
            if ($street === '' || $city === '') return;

            $tenantId = auth()->user()->tenant_id;
            $match = Location::where('tenant_id', $tenantId)
                ->whereRaw('LOWER(street) = LOWER(?)', [$street])
                ->whereRaw('LOWER(city) = LOWER(?)', [$city])
                ->first();

            if ($match) {
                $v->errors()->add(
                    'street',
                    "A location at this address already exists: \"{$match->name}\" (ID #{$match->id}). Use the existing record or add a unique identifier (apt/suite/building) to differentiate."
                );
            }
        });
    }
}
