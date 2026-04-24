<?php

// ─── HospiceController ───────────────────────────────────────────────────────
// Phase C3. Hospice lifecycle endpoints for a single participant.
//
// Routes:
//   POST /participants/{p}/hospice/refer          refer()
//   POST /participants/{p}/hospice/enroll         enroll()
//   POST /participants/{p}/hospice/idt-review     idtReview()
//   POST /participants/{p}/hospice/death          recordDeath()
//   GET  /participants/{p}/bereavement-contacts   bereavementIndex()
//   POST /bereavement-contacts/{contact}/complete completeBereavement()
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BereavementContact;
use App\Models\Participant;
use App\Services\HospiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class HospiceController extends Controller
{
    public function __construct(private HospiceService $svc) {}

    private function gate(array $extra = []): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = array_merge(['primary_care', 'social_work', 'home_care', 'qa_compliance', 'it_admin'], $extra);
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    private function requireSameTenant($resource, $user): void
    {
        abort_if($resource->tenant_id !== $user->tenant_id, 403);
    }

    public function refer(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $validated = $request->validate([
            'hospice_provider_text'  => 'nullable|string|max:200',
            'hospice_diagnosis_text' => 'nullable|string|max:4000',
        ]);
        if (in_array($participant->hospice_status, ['enrolled', 'graduated', 'deceased'], true)) {
            return response()->json([
                'error'   => 'invalid_state',
                'message' => "Cannot refer — participant is already {$participant->hospice_status}.",
            ], 422);
        }
        return response()->json(['participant' => $this->svc->refer($participant, $u, $validated)]);
    }

    public function enroll(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $validated = $request->validate([
            'hospice_started_at'     => 'nullable|date',
            'hospice_provider_text'  => 'nullable|string|max:200',
            'hospice_diagnosis_text' => 'nullable|string|max:4000',
        ]);
        if (in_array($participant->hospice_status, ['graduated', 'deceased'], true)) {
            return response()->json([
                'error'   => 'invalid_state',
                'message' => "Cannot enroll — participant is {$participant->hospice_status}.",
            ], 422);
        }

        $result = $this->svc->enroll($participant, $u, $validated);
        return response()->json($result, 201);
    }

    public function idtReview(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);
        abort_unless($participant->hospice_status === 'enrolled', 422, 'IDT review applies only to hospice-enrolled participants.');

        $validated = $request->validate(['notes' => 'nullable|string|max:4000']);
        return response()->json(['participant' => $this->svc->recordIdtReview($participant, $u, $validated['notes'] ?? null)]);
    }

    public function recordDeath(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        if ($participant->hospice_status === 'deceased') {
            return response()->json(['error' => 'already_deceased'], 409);
        }

        $validated = $request->validate([
            'date_of_death'       => 'required|date|before_or_equal:today',
            'family_contact_name' => 'nullable|string|max:200',
            'family_contact_phone'=> 'nullable|string|max:50',
        ]);

        $result = $this->svc->recordDeath(
            $participant,
            $u,
            Carbon::parse($validated['date_of_death']),
            $validated['family_contact_name'] ?? null,
            $validated['family_contact_phone'] ?? null,
        );
        return response()->json($result, 201);
    }

    public function bereavementIndex(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        return response()->json([
            'contacts' => BereavementContact::forTenant($u->tenant_id)
                ->where('participant_id', $participant->id)
                ->orderBy('scheduled_at')->get(),
        ]);
    }

    public function completeBereavement(Request $request, BereavementContact $contact): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($contact, $u);

        if ($contact->status !== 'scheduled') {
            return response()->json(['error' => 'invalid_state'], 422);
        }

        $validated = $request->validate([
            'outcome' => 'required|in:completed,missed,declined',
            'notes'   => 'nullable|string|max:4000',
        ]);
        $contact->update([
            'status'               => $validated['outcome'],
            'completed_at'         => now(),
            'completed_by_user_id' => $u->id,
            'notes'                => $validated['notes'] ?? null,
        ]);
        AuditLog::record(
            action: 'bereavement.contact_' . $validated['outcome'],
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'bereavement_contact',
            resourceId: $contact->id,
            description: "Bereavement contact marked {$validated['outcome']}.",
        );

        return response()->json(['contact' => $contact->fresh()]);
    }
}
