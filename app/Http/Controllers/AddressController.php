<?php

namespace App\Http\Controllers;

/*
 * AddressController handles create and update operations for participant addresses.
 * Each address belongs to one participant and is tenant-isolated. Address types map
 * to the emr_participant_addresses enum (home, center, emergency, other).
 */

use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\ParticipantAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    // Valid address types matching the DB enum
    private const VALID_TYPES = ['home', 'center', 'emergency', 'other'];

    /**
     * Store a new address for the participant.
     */
    public function store(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $data = $request->validate([
            'address_type'   => ['required', 'in:' . implode(',', self::VALID_TYPES)],
            'street'         => ['required', 'string', 'max:200'],
            'unit'           => ['nullable', 'string', 'max:30'],
            'city'           => ['required', 'string', 'max:100'],
            'state'          => ['required', 'string', 'max:2'],
            'zip'            => ['required', 'string', 'max:10'],
            'notes'          => ['nullable', 'string', 'max:1000'],
            'is_primary'     => ['boolean'],
            'effective_date' => ['nullable', 'date'],
        ]);

        $address = ParticipantAddress::create(array_merge($data, [
            'participant_id' => $participant->id,
        ]));

        AuditLog::record(
            action:       'participant.address.created',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Address ({$address->address_type}) added to {$participant->mrn}",
        );

        return response()->json($address, 201);
    }

    /**
     * Update an existing address. Only allows updating addresses belonging to this participant.
     */
    public function update(Request $request, Participant $participant, ParticipantAddress $address): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        abort_if($address->participant_id !== $participant->id, 404);

        $data = $request->validate([
            'address_type'   => ['sometimes', 'in:' . implode(',', self::VALID_TYPES)],
            'street'         => ['sometimes', 'required', 'string', 'max:200'],
            'unit'           => ['nullable', 'string', 'max:30'],
            'city'           => ['sometimes', 'required', 'string', 'max:100'],
            'state'          => ['sometimes', 'required', 'string', 'max:2'],
            'zip'            => ['sometimes', 'required', 'string', 'max:10'],
            'notes'          => ['nullable', 'string', 'max:1000'],
            'is_primary'     => ['boolean'],
            'effective_date' => ['nullable', 'date'],
        ]);

        $address->update($data);

        AuditLog::record(
            action:       'participant.address.updated',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Address ({$address->address_type}) updated for {$participant->mrn}",
        );

        return response()->json($address->fresh());
    }

    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->tenant_id, 403);
    }
}
