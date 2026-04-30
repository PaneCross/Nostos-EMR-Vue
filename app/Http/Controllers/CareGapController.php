<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\CareGap;
use App\Models\Participant;
use App\Services\CareGapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CareGapController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
    }

    /** GET /care-gaps/summary : tenant-wide by measure. */
    public function summary(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $rows = DB::table('emr_care_gaps')
            ->where('tenant_id', $u->effectiveTenantId())
            ->groupBy('measure')
            ->select('measure', DB::raw('SUM(CASE WHEN satisfied THEN 1 ELSE 0 END) AS satisfied'),
                DB::raw('SUM(CASE WHEN NOT satisfied THEN 1 ELSE 0 END) AS open'))
            ->get();
        return response()->json(['rows' => $rows]);
    }

    /** GET /care-gaps/my-panel : for a PCP. */
    public function myPanel(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $rows = CareGap::forTenant($u->effectiveTenantId())->open()
            ->whereIn('participant_id', function ($q) use ($u) {
                $q->select('id')->from('emr_participants')
                    ->where('tenant_id', $u->effectiveTenantId())
                    ->where('primary_care_user_id', $u->id);
            })
            ->with('participant:id,mrn,first_name,last_name')
            ->orderBy('measure')->get();
        return response()->json(['gaps' => $rows]);
    }

    /** GET /participants/{participant}/care-gaps */
    public function forParticipant(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->effectiveTenantId(), 403);
        return response()->json([
            'gaps' => CareGap::forTenant($u->effectiveTenantId())
                ->where('participant_id', $participant->id)->get(),
        ]);
    }

    /**
     * POST /care-gaps/recompute-all : re-evaluate all 7 measures for every
     * enrolled participant in the caller's effective tenant. Drives the
     * "Recompute" button on /dashboards/gaps so demos / testing can refresh
     * without waiting for the 02:00 scheduled job. Idempotent, the service
     * uses updateOrCreate keyed on (tenant, participant, measure).
     */
    public function recomputeAll(Request $request, CareGapService $svc): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $tenantId = $u->effectiveTenantId();

        $participants = Participant::where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('enrollment_status', 'enrolled')
            ->get();

        $totalGaps = 0;
        $openGaps  = 0;
        foreach ($participants as $p) {
            $results = $svc->evaluate($p);
            $totalGaps += count($results);
            $openGaps  += collect($results)->where('satisfied', false)->count();
        }

        return response()->json([
            'participants_evaluated' => $participants->count(),
            'gap_rows_written'       => $totalGaps,
            'open_gaps'              => $openGaps,
            'computed_at'            => now()->toIso8601String(),
        ]);
    }

    /** GET /dashboards/readmission-risk : high LACE+ flagged. */
    public function readmissionRisk(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        // Assessment table has responses JSON with lace_plus_index band/total for each participant.
        $rows = Assessment::where('tenant_id', $u->effectiveTenantId())
            ->where('assessment_type', 'lace_plus_index')
            ->where('created_at', '>=', now()->subDays(90))
            ->with('participant:id,mrn,first_name,last_name')
            ->orderByDesc('score')
            ->limit(50)->get();
        return response()->json(['rows' => $rows]);
    }
}
