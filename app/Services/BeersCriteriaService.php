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
    /**
     * Return Beers-flagged meds for this participant's active list.
     * @return array<int, array{medication_id:int, drug_name:string, flags:array}>
     */
    public function evaluate(Participant $p): array
    {
        $meds = Medication::where('participant_id', $p->id)
            ->where('status', 'active')->get();

        $out = [];
        foreach ($meds as $m) {
            $flags = BeersCriterion::forDrugName($m->drug_name)
                ->map(fn ($c) => [
                    'risk_category'    => $c->risk_category,
                    'rationale'        => $c->rationale,
                    'recommendation'   => $c->recommendation,
                    'evidence_quality' => $c->evidence_quality,
                ])->values()->all();
            if (! empty($flags)) {
                $out[] = [
                    'medication_id' => $m->id,
                    'drug_name'     => $m->drug_name,
                    'flags'         => $flags,
                ];
            }
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
