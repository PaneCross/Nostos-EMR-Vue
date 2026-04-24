<?php

// ─── DischargeEventController ────────────────────────────────────────────────
// Phase C4. Discharge checklist CRUD + per-item completion.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DischargeEvent;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class DischargeEventController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        $allow = ['primary_care', 'home_care', 'pharmacy', 'social_work', 'qa_compliance', 'it_admin'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    private function requireSameTenant($r, $u): void { abort_if($r->tenant_id !== $u->tenant_id, 403); }

    public function index(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $events = DischargeEvent::forTenant($u->tenant_id)
            ->where('participant_id', $participant->id)
            ->orderByDesc('discharged_on')->get();

        return response()->json(['events' => $events]);
    }

    public function store(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $validated = $request->validate([
            'discharge_from_facility' => 'required|string|max:200',
            'discharged_on'           => 'required|date',
            'readmission_risk_score'  => 'nullable|numeric|min:0|max:99.99',
            'notes'                   => 'nullable|string|max:4000',
        ]);

        $dischargedOn = Carbon::parse($validated['discharged_on']);
        $event = DischargeEvent::create(array_merge($validated, [
            'tenant_id'         => $u->tenant_id,
            'participant_id'    => $participant->id,
            'checklist'         => DischargeEvent::buildDefaultChecklist($dischargedOn),
            'created_by_user_id'=> $u->id,
        ]));

        AuditLog::record(
            action: 'discharge.event_created',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'discharge_event',
            resourceId: $event->id,
            description: "Discharge event created from {$validated['discharge_from_facility']} for participant #{$participant->id}.",
        );

        return response()->json(['event' => $event], 201);
    }

    /**
     * Mark a single checklist item complete.
     * POST /discharge-events/{event}/items/{key}/complete
     */
    public function completeItem(Request $request, DischargeEvent $event, string $key): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($event, $u);

        $validated = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        $list = $event->checklist ?? [];
        $found = false;
        foreach ($list as &$item) {
            if ($item['key'] === $key) {
                if (! empty($item['completed_at'])) {
                    return response()->json(['error' => 'already_completed'], 409);
                }
                $item['completed_at'] = now()->toIso8601String();
                $item['completed_by_user_id'] = $u->id;
                $item['notes'] = $validated['notes'] ?? null;
                $found = true;
                break;
            }
        }
        unset($item);

        if (! $found) {
            return response()->json(['error' => 'item_not_found'], 404);
        }

        $event->update(['checklist' => $list]);

        AuditLog::record(
            action: 'discharge.checklist_item_completed',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'discharge_event',
            resourceId: $event->id,
            description: "Discharge checklist item '{$key}' completed.",
        );

        return response()->json(['event' => $event->fresh()]);
    }
}
