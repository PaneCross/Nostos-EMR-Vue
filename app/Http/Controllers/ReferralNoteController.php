<?php

// ─── ReferralNoteController ───────────────────────────────────────────────────
// Single-endpoint controller for appending notes to an enrollment referral.
// Notes are immutable once written : no update or delete routes exist.
//
// Routes:
//   POST /enrollment/referrals/{referral}/notes  → store()  Append a note
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\StoreReferralNoteRequest;
use App\Models\AuditLog;
use App\Models\Referral;
use App\Models\ReferralNote;
use Illuminate\Http\JsonResponse;

class ReferralNoteController extends Controller
{
    /**
     * POST /enrollment/referrals/{referral}/notes
     * Append a note to the referral's thread.
     * Write-restricted to enrollment / it_admin / super_admin.
     */
    public function store(StoreReferralNoteRequest $request, Referral $referral): JsonResponse
    {
        $user = $request->user();

        // Tenant guard : reject cross-tenant referrals (defensive; route model
        // binding already constrains to this tenant's data in practice).
        abort_if($referral->tenant_id !== $user->effectiveTenantId(), 403);

        // Write permission : same roles that can transition referral status.
        abort_unless(
            $user->role === 'super_admin'
            || in_array($user->department, ['enrollment', 'it_admin', 'super_admin'], true),
            403,
            'Only enrollment staff can add notes to referrals.'
        );

        $note = ReferralNote::create([
            'tenant_id'       => $user->effectiveTenantId(),
            'referral_id'     => $referral->id,
            'user_id'         => $user->id,
            'content'         => $request->validated()['content'],
            'referral_status' => $referral->status,  // capture status at write time
        ]);

        AuditLog::record(
            action:       'referral.note_added',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'referral',
            resourceId:   $referral->id,
            description:  "Note added to referral #{$referral->id}",
            newValues:    ['note_id' => $note->id, 'content_length' => strlen($note->content)],
        );

        $note->load('user:id,first_name,last_name,department');

        return response()->json(['note' => $note], 201);
    }
}
