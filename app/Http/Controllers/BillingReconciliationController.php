<?php

// ─── BillingReconciliationController : Phase M6 ─────────────────────────────
namespace App\Http\Controllers;

use App\Models\CapitationRecord;
use App\Models\PdeRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class BillingReconciliationController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        abort_unless(
            $u->isSuperAdmin() || in_array($u->department, ['finance', 'qa_compliance', 'executive', 'it_admin']),
            403
        );
    }

    /** GET /billing/pde-reconciliation.json : submitted vs paid vs variance */
    public function pdeJson(): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $rows = PdeRecord::forTenant($u->tenant_id)
            ->selectRaw("
                DATE_TRUNC('month', dispense_date)::date AS month,
                SUM(CASE WHEN submission_status = 'submitted' THEN ingredient_cost + dispensing_fee ELSE 0 END) AS submitted,
                SUM(CASE WHEN submission_status = 'paid'      THEN ingredient_cost + dispensing_fee ELSE 0 END) AS paid
            ")
            ->groupByRaw("DATE_TRUNC('month', dispense_date)")
            ->orderBy('month')
            ->get();

        $rows->each(function ($r) {
            $r->variance = (float) $r->submitted - (float) $r->paid;
        });

        return response()->json(['rows' => $rows]);
    }

    /** GET /billing/capitation-reconciliation.json : MMR vs local expected */
    public function capitationJson(): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $rows = CapitationRecord::forTenant($u->tenant_id)
            ->selectRaw("
                month_year,
                COUNT(*) AS participant_count,
                SUM(total_capitation) AS local_expected
            ")
            ->groupBy('month_year')
            ->orderBy('month_year')
            ->get();

        return response()->json(['rows' => $rows]);
    }
}
