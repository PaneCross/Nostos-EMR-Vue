<?php

namespace App\Http\Controllers;

use App\Models\QualityMeasure;
use App\Models\QualityMeasureSnapshot;
use App\Services\QualityMeasureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class QualityMeasureController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
    }

    /** GET /quality-measures : list seeded measures. */
    public function index(): JsonResponse
    {
        $this->gate();
        return response()->json(['measures' => QualityMeasure::orderBy('measure_id')->get()]);
    }

    /** GET /quality-measures/snapshots?days=90 : trend per measure. */
    public function snapshots(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $days = (int) $request->query('days', 90);
        $rows = QualityMeasureSnapshot::forTenant($u->tenant_id)
            ->where('computed_at', '>=', now()->subDays($days))
            ->orderBy('measure_id')->orderBy('computed_at')->get();
        return response()->json(['rows' => $rows->groupBy('measure_id')]);
    }

    /** POST /quality-measures/compute : on-demand. */
    public function compute(Request $request, QualityMeasureService $svc): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $snaps = $svc->computeAll($u->tenant_id);
        return response()->json(['snapshots' => $snaps]);
    }
}
