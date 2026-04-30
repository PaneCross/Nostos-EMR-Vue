<?php

// ─── ParticipantRafController : Phase R10 ───────────────────────────────────
// Clinician-accessible per-participant RAF dashboard. Surfaces:
//   - Current-year RAF score (CMS-HCC V28 per HccMappingSeeder)
//   - Prior-year RAF score (delta visibility for V28 transition)
//   - HCC gaps: documented diagnoses missing from this year's encounter data
// Available to primary_care, pharmacy, qa_compliance, finance, executive.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Participant;
use App\Services\HccRiskScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ParticipantRafController extends Controller
{
    public function __construct(private HccRiskScoringService $hcc) {}

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = ['primary_care', 'pharmacy', 'qa_compliance', 'finance', 'executive', 'it_admin'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    public function show(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->effectiveTenantId(), 403);

        $year = (int) $request->query('year', now()->year);
        $priorYear = $year - 1;

        $current = $this->hcc->calculateRafScore($participant->id, $year);
        $prior   = $this->hcc->calculateRafScore($participant->id, $priorYear);
        $gaps    = $this->hcc->findHccGaps($participant->id, $year);

        $delta = round($current['raf_score'] - $prior['raf_score'], 4);

        return response()->json([
            'participant'    => $participant->only(['id', 'mrn', 'first_name', 'last_name']),
            'model_label'    => 'CMS-HCC V28',
            'current_year'   => $year,
            'prior_year'     => $priorYear,
            'current'        => $current,
            'prior'          => $prior,
            'delta'          => $delta,
            'delta_label'    => $delta > 0 ? 'increase' : ($delta < 0 ? 'decrease' : 'unchanged'),
            'hcc_gaps'       => $gaps,
            'gap_count'      => count($gaps),
            'honest_label'   => 'RAF computed locally from emr_problems × emr_hcc_mappings. Final CMS-published RAF may differ : see CMS HPMS report.',
        ]);
    }
}
