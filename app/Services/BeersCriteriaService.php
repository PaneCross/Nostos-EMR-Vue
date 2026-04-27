<?php

// ─── BeersCriteriaService ────────────────────────────────────────────────────
// Phase C6. Evaluates a participant's active medication list against the
// AGS Beers Criteria reference table and returns flagged PIMs.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\BeersCriterion;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\PolypharmacyReview;

class BeersCriteriaService
{
    /** Cached criterion table : loaded once per service instance. */
    private ?\Illuminate\Support\Collection $criterionCache = null;

    /**
     * Return Beers-flagged meds for this participant's active list.
     * @return array<int, array{medication_id:int, drug_name:string, flags:array}>
     */
    public function evaluate(Participant $p): array
    {
        $meds = Medication::where('participant_id', $p->id)
            ->where('status', 'active')->get();

        return $this->evaluateMedications($meds);
    }

    /**
     * Phase P8 : batch path for tenant-wide rollups (PharmacyDashboard.beersRollup).
     * One query for all active meds + one query for all criteria, then
     * everything else runs in PHP. Was N×(2-query) per participant; now O(1).
     *
     * @param \Illuminate\Support\Collection<int, Participant> $participants
     * @return array<int, array{participant_id:int, flags:array<int, array{medication_id:int, drug_name:string, flags:array}>}>
     */
    public function evaluateBatch(\Illuminate\Support\Collection $participants): array
    {
        if ($participants->isEmpty()) return [];
        $ids = $participants->pluck('id')->all();

        $medsByParticipant = Medication::whereIn('participant_id', $ids)
            ->where('status', 'active')
            ->get()
            ->groupBy('participant_id');

        $out = [];
        foreach ($participants as $p) {
            $meds = $medsByParticipant->get($p->id, collect());
            $flags = $this->evaluateMedications($meds);
            if (! empty($flags)) {
                $out[] = ['participant_id' => $p->id, 'flags' => $flags];
            }
        }
        return $out;
    }

    /**
     * Apply criteria against a med collection in pure PHP. Caches the criteria
     * table so per-call repeats (in batch loops) skip the round-trip.
     */
    private function evaluateMedications(\Illuminate\Support\Collection $meds): array
    {
        if ($meds->isEmpty()) return [];
        $criteria = $this->criterionCache ??= BeersCriterion::all();

        $out = [];
        foreach ($meds as $m) {
            $matches = $criteria->filter(fn (BeersCriterion $c) => stripos($m->drug_name, $c->drug_keyword) !== false);
            if ($matches->isEmpty()) continue;
            $out[] = [
                'medication_id' => $m->id,
                'drug_name'     => $m->drug_name,
                'flags'         => $matches->map(fn (BeersCriterion $c) => [
                    'risk_category'    => $c->risk_category,
                    'rationale'        => $c->rationale,
                    'recommendation'   => $c->recommendation,
                    'evidence_quality' => $c->evidence_quality,
                ])->values()->all(),
            ];
        }
        return $out;
    }

    /** True if participant has ≥POLYPHARMACY_THRESHOLD active meds. */
    public function isPolypharmacy(Participant $p): bool
    {
        return Medication::where('participant_id', $p->id)
            ->where('status', 'active')->count()
            >= PolypharmacyReview::POLYPHARMACY_THRESHOLD;
    }
}
