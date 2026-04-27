<?php

// ─── ShortWinsF1Controller ───────────────────────────────────────────────────
// Phase F1 : batches 5 lightweight surfaces:
//   - Immunization forecasting widget (flu shots due in 30d)
//   - Wound-photo attachments (CRUD)
//   - Goals-of-care conversation log
//   - Late-dose trend widget (30-day eMAR late count)
//   - (Critical-value ack already shipped in B6 : cross-reference only)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EmarRecord;
use App\Models\GoalsOfCareConversation;
use App\Models\Immunization;
use App\Models\Participant;
use App\Models\WoundPhoto;
use App\Models\WoundRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ShortWinsF1Controller extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
    }

    /** GET /widgets/immunization-forecast : shots due in next 30 days. */
    public function immunizationForecast(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $from = now()->toDateString();
        $to   = now()->addDays(30)->toDateString();

        $rows = Immunization::where('tenant_id', $u->tenant_id)
            ->whereNotNull('next_dose_due')
            ->whereBetween('next_dose_due', [$from, $to])
            ->with('participant:id,mrn,first_name,last_name')
            ->orderBy('next_dose_due')->get();

        return response()->json([
            'rows'  => $rows,
            'count' => $rows->count(),
        ]);
    }

    /** GET /widgets/late-doses : EmarRecord status='late' in last 30 days, grouped by day. */
    public function lateDoseTrend(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();

        $rows = DB::table('emr_emar_records')
            ->where('tenant_id', $u->tenant_id)
            ->where('status', 'late')
            ->where('scheduled_time', '>=', now()->subDays(30))
            ->selectRaw('DATE(scheduled_time) AS day, COUNT(*) AS count')
            ->groupBy('day')->orderBy('day')->get();

        return response()->json([
            'rows'  => $rows,
            'total' => (int) $rows->sum('count'),
        ]);
    }

    /** GET /wounds/{wound}/photos */
    public function wondPhotosIndex(Request $request, WoundRecord $wound): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($wound->tenant_id !== $u->tenant_id, 403);
        $rows = WoundPhoto::forTenant($u->tenant_id)
            ->where('wound_id', $wound->id)
            ->orderByDesc('taken_at')->get();
        return response()->json(['photos' => $rows]);
    }

    /** POST /wounds/{wound}/photos */
    public function woundPhotosStore(Request $request, WoundRecord $wound): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($wound->tenant_id !== $u->tenant_id, 403);

        $validated = $request->validate([
            'document_id' => 'nullable|integer|exists:emr_documents,id',
            'taken_at'    => 'required|date',
            'notes'       => 'nullable|string|max:2000',
        ]);
        $photo = WoundPhoto::create(array_merge($validated, [
            'tenant_id'        => $u->tenant_id,
            'wound_id'         => $wound->id,
            'taken_by_user_id' => $u->id,
        ]));
        AuditLog::record(
            action: 'wound.photo_attached',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'wound_record',
            resourceId: $wound->id,
            description: "Wound photo attached (photo #{$photo->id}).",
        );
        return response()->json(['photo' => $photo], 201);
    }

    /** GET /participants/{participant}/goals-of-care */
    public function goalsOfCareIndex(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->tenant_id, 403);
        $rows = GoalsOfCareConversation::forTenant($u->tenant_id)
            ->where('participant_id', $participant->id)
            ->orderByDesc('conversation_date')->get();
        return response()->json(['conversations' => $rows]);
    }

    /** POST /participants/{participant}/goals-of-care */
    public function goalsOfCareStore(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->tenant_id, 403);

        $validated = $request->validate([
            'conversation_date'    => 'required|date|before_or_equal:today',
            'participants_present' => 'nullable|string|max:400',
            'discussion_summary'   => 'required|string|min:10|max:8000',
            'decisions_made'       => 'nullable|string|max:4000',
            'next_steps'           => 'nullable|string|max:2000',
        ]);
        $c = GoalsOfCareConversation::create(array_merge($validated, [
            'tenant_id'           => $u->tenant_id,
            'participant_id'      => $participant->id,
            'recorded_by_user_id' => $u->id,
        ]));
        return response()->json(['conversation' => $c], 201);
    }
}
