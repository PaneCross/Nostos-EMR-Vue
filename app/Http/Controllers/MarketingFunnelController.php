<?php

// ─── MarketingFunnelController : Phase R9 ───────────────────────────────────
// Aggregates Referral pipeline into a marketing/lead-source funnel:
//   - per-source totals
//   - per-source conversion rates (referrals → intake_complete → enrolled)
//   - decline reasons rollup
// Read-only; renders an Inertia page at /enrollment/marketing-funnel.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Referral;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MarketingFunnelController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = ['enrollment', 'executive', 'qa_compliance', 'it_admin', 'finance'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    public function index(Request $request)
    {
        $this->gate();
        $u = Auth::user();
        $from = $request->query('from', Carbon::now()->subYear()->toDateString());
        $to   = $request->query('to',   Carbon::now()->toDateString());

        $referrals = Referral::forTenant($u->effectiveTenantId())
            ->whereBetween('referral_date', [$from, $to])
            ->get(['id', 'referral_source', 'status', 'decline_reason']);

        // Per-source funnel
        $bySource = [];
        foreach ($referrals as $r) {
            $src = $r->referral_source ?: 'unspecified';
            if (! isset($bySource[$src])) {
                $bySource[$src] = [
                    'source'     => $src,
                    'total'      => 0,
                    'in_pipeline'=> 0,
                    'enrolled'   => 0,
                    'declined'   => 0,
                    'withdrawn'  => 0,
                ];
            }
            $bySource[$src]['total']++;
            match ($r->status) {
                'enrolled'  => $bySource[$src]['enrolled']++,
                'declined'  => $bySource[$src]['declined']++,
                'withdrawn' => $bySource[$src]['withdrawn']++,
                default     => $bySource[$src]['in_pipeline']++,
            };
        }
        // Conversion rate column
        foreach ($bySource as &$row) {
            $row['conversion_rate_pct'] = $row['total'] > 0
                ? round(100 * $row['enrolled'] / $row['total'], 1)
                : 0;
        }
        unset($row);
        // Sort by total desc
        usort($bySource, fn ($a, $b) => $b['total'] <=> $a['total']);

        // Pipeline funnel totals
        $totals = [
            'leads'           => $referrals->count(),
            'intake_complete' => $referrals->whereIn('status', ['intake_complete', 'eligibility_pending', 'pending_enrollment', 'enrolled'])->count(),
            'enrolled'        => $referrals->where('status', 'enrolled')->count(),
            'declined'        => $referrals->where('status', 'declined')->count(),
            'withdrawn'       => $referrals->where('status', 'withdrawn')->count(),
        ];
        $totals['enrollment_rate_pct'] = $totals['leads'] > 0
            ? round(100 * $totals['enrolled'] / $totals['leads'], 1)
            : 0;

        // Decline-reason rollup
        $declineReasons = $referrals->where('status', 'declined')
            ->groupBy('decline_reason')
            ->map(fn ($group, $reason) => [
                'reason' => $reason ?: 'unspecified',
                'count'  => $group->count(),
            ])
            ->values()
            ->sortByDesc('count')
            ->values();

        return \Inertia\Inertia::render('Enrollment/MarketingFunnel', [
            'from'            => $from,
            'to'              => $to,
            'totals'          => $totals,
            'by_source'       => array_values($bySource),
            'decline_reasons' => $declineReasons,
        ]);
    }
}
