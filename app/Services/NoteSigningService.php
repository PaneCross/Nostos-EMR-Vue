<?php

// ─── NoteSigningService ───────────────────────────────────────────────────────
// Handles the two write operations that change a clinical note's state:
// signing a draft note (making it permanently immutable) and adding an addendum
// to a note that's already been signed.
//
// Extracted from ClinicalNoteController so the business rules for note signing
// — audit logging, event broadcasting, HIPAA immutability enforcement — live
// in one place and don't clutter the HTTP layer.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Events\ClinicalNoteSignedEvent;
use App\Http\Requests\StoreClinicalNoteRequest;
use App\Models\AuditLog;
use App\Models\ClinicalNote;
use App\Models\Participant;
use App\Models\User;

class NoteSigningService
{
    /**
     * Sign a draft note. Sets signed_at, signed_by_user_id, and status=signed.
     * Once signed, the note is permanently immutable (enforced by ClinicalNote::canEdit()).
     * Broadcasts ClinicalNoteSignedEvent so other open chart tabs refresh in real time.
     */
    public function signNote(ClinicalNote $note, User $user, Participant $participant): ClinicalNote
    {
        $note->sign($user);

        AuditLog::record(
            action:       'participant.note.signed',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "{$note->noteTypeLabel()} note signed for {$participant->mrn}",
            newValues:    ['note_id' => $note->id, 'signed_at' => $note->signed_at],
        );

        broadcast(
            new ClinicalNoteSignedEvent($note->load('author:id,first_name,last_name,department'))
        )->toOthers();

        return $note->fresh('author:id,first_name,last_name', 'signedBy:id,first_name,last_name');
    }

    /**
     * Create an addendum note linked to a signed parent note.
     * Addenda are always created as drafts in the author's department.
     * They must be separately signed by the addendum author before becoming permanent.
     */
    public function createAddendum(
        StoreClinicalNoteRequest $request,
        ClinicalNote $parentNote,
        Participant $participant,
        User $user,
    ): ClinicalNote {
        $addendum = ClinicalNote::create(array_merge($request->validated(), [
            'participant_id'      => $participant->id,
            'tenant_id'           => $user->tenant_id,
            'site_id'             => $participant->site_id,
            'authored_by_user_id' => $user->id,
            'note_type'           => 'addendum',
            'status'              => ClinicalNote::STATUS_DRAFT,
            'parent_note_id'      => $parentNote->id,
        ]));

        AuditLog::record(
            action:       'participant.note.addendum_created',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Addendum created for note #{$parentNote->id} on {$participant->mrn}",
            newValues:    ['addendum_id' => $addendum->id, 'parent_note_id' => $parentNote->id],
        );

        return $addendum->load('author:id,first_name,last_name');
    }
}
