<?php

// ─── IbnrController — Phase S5 ──────────────────────────────────────────────
namespace App\Http\Controllers;

use App\Services\IbnrEstimateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IbnrController extends Controller
{
    public function __construct(private IbnrEstimateService $svc) {}

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = ['finance', 'qa_compliance', 'it_admin', 'executive'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    public function index(Request $request)
    {
        $this->gate();
        $u = Auth::user();
        $months = max(1, min(12, (int) $request->query('months', 6)));
        $estimate = $this->svc->estimate($u->tenant_id, $months);

        return \Inertia\Inertia::render('Billing/Ibnr', [
            'months_back' => $months,
            'estimate'    => $estimate,
            'honest_label' => 'Directional IBNR estimate using lag-based completion factors. Not actuarial-grade — for finance-team monthly close visibility only. A real actuarial model would use development triangles and payer-specific lag patterns.',
        ]);
    }
}
