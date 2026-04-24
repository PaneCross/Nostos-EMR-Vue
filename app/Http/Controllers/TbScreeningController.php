<?php

// ─── TbScreeningController ───────────────────────────────────────────────────
// Phase C2a. TB screening CRUD. 42 CFR §460.71 — annual cadence.
//
// Routes:
//   GET  /participants/{p}/tb-screenings        index()
//   POST /participants/{p}/tb-screenings        store()
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\TbScreening;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class TbScreeningController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        $allow = ['primary_care', 'home_care', 'qa_compliance', 'it_admin'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    private function requireSameTenant($resource, $user): void
    {
        abort_if($resource->tenant_id !== $user->tenant_id, 403);
    }

    public function index(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $records = TbScreening::forTenant($u->tenant_id)
            ->where('participant_id', $participant->id)
            ->with('recordedBy:id,first_name,last_name')
            ->orderByDesc('performed_date')
            ->get();

        $latest = $records->first();

        return response()->json([
            'records'        => $records,
            'latest'         => $latest,
            'days_until_due' => $latest?->daysUntilDue(),
        ]);
    }

    public function store(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $validated = $request->validate([
            'screening_type' => 'required|in:' . implode(',', TbScreening::TYPES),
            'performed_date' => 'required|date|before_or_equal:today',
            'result'         => 'required|in:' . implode(',', TbScreening::RESULTS),
            'induration_mm'  => 'nullable|numeric|min:0|max:99.9',
            'follow_up_text' => 'nullable|string|max:4000',
            'notes'          => 'nullable|string|max:4000',
        ]);

        // PPD requires induration_mm.
        if ($validated['screening_type'] === 'ppd' && ! isset($validated['induration_mm'])) {
            return response()->json([
                'error' => 'induration_mm_required',
                'message' => 'PPD screenings require induration_mm to be recorded.',
            ], 422);
        }

        $performed = Carbon::parse($validated['performed_date']);
        $record = TbScreening::create(array_merge($validated, [
            'tenant_id'           => $u->tenant_id,
            'participant_id'      => $participant->id,
            'recorded_by_user_id' => $u->id,
            'next_due_date'       => $performed->copy()->addDays(TbScreening::RECERT_DAYS),
        ]));

        AuditLog::record(
            action: 'tb.screening_recorded',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'tb_screening',
            resourceId: $record->id,
            description: "TB {$validated['screening_type']} {$validated['result']} for participant #{$participant->id}.",
        );

        return response()->json(['record' => $record], 201);
    }
}
