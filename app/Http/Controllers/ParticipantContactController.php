<?php

// ─── ParticipantContactController ─────────────────────────────────────────────
// JSON CRUD endpoints for ParticipantContact rows (emergency contacts, legal
// representatives, family) on a single participant chart.
//
// Routes: GET/POST /participants/{p}/contacts and PUT /contacts/{c}.
// Used by the Vue Contacts tab on the chart and the intake/enrollment flow.
//
// Notable rules:
//  - Tenant isolation: every action calls authorizeForTenant() which 403s
//    if the participant's tenant_id != caller's tenant_id (HIPAA §164.312
//    cross-tenant guard).
//  - Every write produces an AuditLog row (HIPAA non-repudiation).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactRequest;
use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\ParticipantContact;
use Illuminate\Http\Request;

class ParticipantContactController extends Controller
{
    public function index(Request $request, Participant $participant)
    {
        $this->authorizeForTenant($participant, $request->user());

        return response()->json(
            $participant->contacts()->orderBy('priority_order')->get()
        );
    }

    public function store(StoreContactRequest $request, Participant $participant)
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $contact = ParticipantContact::create(array_merge($request->validated(), [
            'participant_id' => $participant->id,
        ]));

        AuditLog::record(
            action:       'participant.contact.created',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Contact '{$contact->fullName()}' added to {$participant->mrn}",
        );

        return response()->json($contact, 201);
    }

    public function update(StoreContactRequest $request, Participant $participant, ParticipantContact $contact)
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        abort_if($contact->participant_id !== $participant->id, 404);

        $contact->update($request->validated());

        AuditLog::record(
            action:       'participant.contact.updated',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Contact '{$contact->fullName()}' updated for {$participant->mrn}",
        );

        return response()->json($contact->fresh());
    }

    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->effectiveTenantId(), 403);
    }
}
