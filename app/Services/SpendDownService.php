<?php

// ─── SpendDownService ─────────────────────────────────────────────────────────
// Tracks a participant's Medicaid spend-down / share-of-cost obligation and
// tells finance whether the obligation is met for a given period.
//
// A period is a calendar month ('YYYY-MM'). Within that month, every payment
// or credit row in emr_spend_down_payments sums toward the obligation. Once
// total paid >= obligation, Medicaid coverage is considered active for the
// period and capitation can be billed.
//
// State-specific rule variations (CA share-of-cost, NY surplus, FL spend-down)
// are handled here rather than scattered through UI/controller. For MVP, all
// states use the same monthly-obligation model. State-specific extensions go
// in branches keyed on InsuranceCoverage.spend_down_state.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\InsuranceCoverage;
use App\Models\Participant;
use App\Models\SpendDownPayment;
use Illuminate\Support\Carbon;

class SpendDownService
{
    /**
     * Resolve a participant's current active Medicaid coverage with spend-down.
     * Returns null if the participant doesn't have one.
     */
    public function activeSpendDownCoverage(Participant $participant): ?InsuranceCoverage
    {
        return $participant->insuranceCoverages()
            ->where('payer_type', 'medicaid')
            ->where('is_active', true)
            ->get()
            ->first(fn (InsuranceCoverage $c) => $c->hasSpendDown());
    }

    /**
     * Total paid toward the obligation for a specific period.
     */
    public function totalPaidForPeriod(int $participantId, string $periodYm): float
    {
        return (float) SpendDownPayment::where('participant_id', $participantId)
            ->where('period_month_year', $periodYm)
            ->sum('amount');
    }

    /**
     * Complete status for a (participant, period) pair. Returns null if
     * there's no spend-down obligation.
     *
     * @return array{obligation:float,paid:float,remaining:float,met:bool,state:?string,period:string,coverage_id:int}|null
     */
    public function periodStatus(Participant $participant, string $periodYm): ?array
    {
        $coverage = $this->activeSpendDownCoverage($participant);
        if (! $coverage) return null;

        $obligation = (float) ($coverage->share_of_cost_monthly_amount ?: 0.0);
        $paid       = $this->totalPaidForPeriod($participant->id, $periodYm);
        $remaining  = max(0.0, $obligation - $paid);

        return [
            'obligation'   => round($obligation, 2),
            'paid'         => round($paid, 2),
            'remaining'    => round($remaining, 2),
            'met'          => $remaining <= 0.0,
            'state'        => $coverage->spend_down_state,
            'period'       => $periodYm,
            'coverage_id'  => $coverage->id,
        ];
    }

    /**
     * Is capitation billing for this period blocked by an unmet spend-down?
     * Finance should NOT bill Medicaid capitation until this returns false.
     */
    public function capitationBlocked(Participant $participant, string $periodYm): bool
    {
        $status = $this->periodStatus($participant, $periodYm);
        return $status !== null && ! $status['met'];
    }

    /**
     * "Overdue": current or prior period has an unmet obligation past its
     * calendar end. Used to populate the finance-dashboard worklist.
     *
     * @return array<int, array{participant_id:int,name:string,mrn:?string,period:string,obligation:float,paid:float,remaining:float,state:?string,days_overdue:int}>
     */
    public function overdueForTenant(int $tenantId, int $lookbackMonths = 3): array
    {
        $today = Carbon::today();
        $overdue = [];

        $participants = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->with('insuranceCoverages')
            ->get();

        foreach ($participants as $participant) {
            if (! $this->activeSpendDownCoverage($participant)) continue;

            // Iterate current month back N months. A period is "overdue" once the
            // calendar period has ended and remaining > 0.
            for ($m = 0; $m <= $lookbackMonths; $m++) {
                $periodDate = $today->copy()->subMonths($m);
                $periodYm   = $periodDate->format('Y-m');
                $status     = $this->periodStatus($participant, $periodYm);
                if (! $status || $status['met']) continue;

                $periodEnd = $periodDate->copy()->endOfMonth();
                if ($periodEnd->isFuture()) continue; // current in-progress month not overdue

                $overdue[] = [
                    'participant_id' => $participant->id,
                    'name'           => trim(($participant->first_name ?? '') . ' ' . ($participant->last_name ?? '')),
                    'mrn'            => $participant->mrn ?? null,
                    'period'         => $periodYm,
                    'obligation'     => $status['obligation'],
                    'paid'           => $status['paid'],
                    'remaining'      => $status['remaining'],
                    'state'          => $status['state'],
                    'days_overdue'   => max(0, (int) $periodEnd->diffInDays($today)),
                ];
            }
        }

        return $overdue;
    }
}
