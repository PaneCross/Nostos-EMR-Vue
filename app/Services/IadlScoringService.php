<?php

// ─── IadlScoringService ──────────────────────────────────────────────────────
// Phase C1. Scores a set of Lawton IADL items → total 0–8 + interpretation band.
//
// Bands (standard Lawton cut points for elderly/PACE):
//   8         → independent
//   6-7       → mild_impairment
//   3-5       → moderate_impairment
//   0-2       → severe_impairment
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\IadlRecord;

class IadlScoringService
{
    /**
     * @param  array<string, int> $items  map of item → 0|1
     * @return array{total: int, interpretation: string}
     */
    public function score(array $items): array
    {
        $total = 0;
        foreach (IadlRecord::ITEMS as $k) {
            $v = (int) ($items[$k] ?? 0);
            $total += ($v === 1) ? 1 : 0;
        }
        return [
            'total'          => $total,
            'interpretation' => $this->band($total),
        ];
    }

    public function band(int $total): string
    {
        if ($total === 8)  return 'independent';
        if ($total >= 6)   return 'mild_impairment';
        if ($total >= 3)   return 'moderate_impairment';
        return 'severe_impairment';
    }
}
