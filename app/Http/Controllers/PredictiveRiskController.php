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

    /** GET /participants/{p}/predictive-risk — latest scores. */
    public function forParticipant(Request $request, Participant $participant, PredictiveRiskService $svc): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->tenant_id, 403);

        $latest = PredictiveRiskScore::forTenant($u->tenant_id)
            ->where('participant_id', $participant->id)
            ->orderByDesc('computed_at')->limit(20)->get()
            ->groupBy('risk_type');

        return response()->json([
            'latest'  => $latest->map(fn ($rows) => $rows->first()),
            'history' => $latest,
        ]);
    }

    /** POST /participants/{p}/predictive-risk/compute — on-demand. */
    public function compute(Request $request, Participant $participant, PredictiveRiskService $svc): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->tenant_id, 403);
        $scores = $svc->score($participant);
        return response()->json(['scores' => $scores]);
    }

    /**
     * GET /dashboards/high-risk — top-N high risk across tenant.
     * Phase O3: dual-serve JSON + Inertia via wantsJson() branch.
     */
    public function highRisk(Request $request): JsonResponse|\Inertia\Response
    {
        $this->gate();
        $u = Auth::user();
        if (! $request->wantsJson()) return \Inertia\Inertia::render('Dashboards/HighRisk');
        // latest row per participant per risk_type — cheap approximation:
        // grab scores from last 24h only, since job runs daily.
        $rows = PredictiveRiskScore::forTenant($u->tenant_id)
            ->high()
            ->where('computed_at', '>=', now()->subDay())
            ->with('participant:id,mrn,first_name,last_name')
            ->orderByDesc('score')->limit(50)->get();
        return response()->json(['rows' => $rows]);
    }
}
