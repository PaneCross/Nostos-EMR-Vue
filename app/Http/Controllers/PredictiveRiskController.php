<?php

namespace App\Http\Controllers;

use App\Models\Participant;
use App\Models\PredictiveRiskScore;
use App\Services\PredictiveRiskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PredictiveRiskController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
    }

    /** GET /participants/{p}/predictive-risk : latest scores. */
    public function forParticipant(Request $request, Participant $participant, PredictiveRiskService $svc): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->effectiveTenantId(), 403);

        $latest = PredictiveRiskScore::forTenant($u->effectiveTenantId())
            ->where('participant_id', $participant->id)
            ->orderByDesc('computed_at')->limit(20)->get()
            ->groupBy('risk_type');

        return response()->json([
            'latest'  => $latest->map(fn ($rows) => $rows->first()),
            'history' => $latest,
        ]);
    }

    /** POST /participants/{p}/predictive-risk/compute : on-demand. */
    public function compute(Request $request, Participant $participant, PredictiveRiskService $svc): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->effectiveTenantId(), 403);
        $scores = $svc->score($participant);
        return response()->json(['scores' => $scores]);
    }

    /**
     * POST /predictive-risk/recompute-all : run the heuristic for every
     * enrolled participant in the caller's effective tenant. Used by the
     * "Recompute" button on /dashboards/high-risk so demos / tests don't
     * have to wait for the 03:00 scheduled job. Mirrors what
     * PredictiveRiskScoringJob does but scoped to one tenant on demand.
     *
     * Returns a count summary, not the score rows themselves, the
     * dashboard reloads its own list right after.
     */
    public function recomputeAll(Request $request, PredictiveRiskService $svc): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        // Read-permission proxy : if the user can see /dashboards/high-risk,
        // they can recompute. The dashboard route itself is auth-gated by
        // the route group; no separate role check needed here.
        $tenantId = $u->effectiveTenantId();

        $participants = Participant::where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('enrollment_status', 'enrolled')
            ->get();

        $byBand = ['high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($participants as $p) {
            foreach ($svc->score($p) as $score) {
                $byBand[$score->band] = ($byBand[$score->band] ?? 0) + 1;
            }
        }

        return response()->json([
            'participants_scored' => $participants->count(),
            'by_band'             => $byBand,
            'computed_at'         => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /dashboards/high-risk : top-N high risk across tenant.
     * Phase O3: dual-serve JSON + Inertia via wantsJson() branch.
     */
    public function highRisk(Request $request): JsonResponse|\Inertia\Response
    {
        $this->gate();
        $u = Auth::user();
        if (! $request->wantsJson()) return \Inertia\Inertia::render('Dashboards/HighRisk');
        // latest row per participant per risk_type : cheap approximation:
        // grab scores from last 24h only, since job runs daily.
        $rows = PredictiveRiskScore::forTenant($u->effectiveTenantId())
            ->high()
            ->where('computed_at', '>=', now()->subDay())
            ->with('participant:id,mrn,first_name,last_name')
            ->orderByDesc('score')->limit(50)->get();
        return response()->json(['rows' => $rows]);
    }
}
