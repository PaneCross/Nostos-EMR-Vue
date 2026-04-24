<?php

// ─── CareGapService ──────────────────────────────────────────────────────────
// Phase G1. Evaluates each participant against 7 preventive-care measures and
// persists a CareGap row per (participant, measure). Idempotent via
// updateOrCreate.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\CareGap;
use App\Models\ClinicalNote;
use App\Models\Immunization;
use App\Models\Participant;
use App\Models\Problem;
use Carbon\Carbon;

class CareGapService
{
    public function evaluate(Participant $p): array
    {
        $results = [];
        foreach (CareGap::MEASURES as $m) {
            $res = $this->evaluateMeasure($p, $m);
            CareGap::updateOrCreate(
                ['tenant_id' => $p->tenant_id, 'participant_id' => $p->id, 'measure' => $m],
                array_merge($res, ['calculated_at' => now()]),
            );
            $results[] = array_merge(['measure' => $m], $res);
        }
        return $results;
    }

    private function evaluateMeasure(Participant $p, string $measure): array
    {
        return match ($measure) {
            'annual_pcp_visit' => $this->annualPcpVisit($p),
            'flu_shot'         => $this->fluShot($p),
            'pneumococcal'     => $this->pneumococcal($p),
            'colonoscopy'      => $this->ageGated($p, 45, 75, null, 10 * 365, 'colonoscopy'),
            'mammogram'        => $this->mammogram($p),
            'a1c'              => $this->diabetesMeasure($p, 'a1c', 180),
            'diabetic_eye_exam'=> $this->diabetesMeasure($p, 'diabetic_eye_exam', 365),
            default            => ['satisfied' => false, 'last_satisfied_date' => null, 'next_due_date' => null, 'reason_open' => 'unknown measure'],
        };
    }

    private function annualPcpVisit(Participant $p): array
    {
        $last = ClinicalNote::where('participant_id', $p->id)
            ->whereIn('note_type', ['soap', 'progress_nursing'])
            ->orderByDesc('visit_date')->first();
        $cutoff = now()->subDays(365);
        $satisfied = $last && $last->visit_date && $last->visit_date->gte($cutoff);
        return [
            'satisfied' => $satisfied,
            'last_satisfied_date' => $last?->visit_date?->toDateString(),
            'next_due_date' => $last?->visit_date?->copy()->addYear()->toDateString(),
            'reason_open' => $satisfied ? null : 'No PCP visit in last 12 months',
        ];
    }

    private function fluShot(Participant $p): array
    {
        // Flu season: Aug 1 → Mar 31; count a shot "this season" if admin_date >= last Aug 1.
        $seasonStart = now()->month >= 8
            ? Carbon::create(now()->year, 8, 1)
            : Carbon::create(now()->year - 1, 8, 1);
        $shot = Immunization::where('participant_id', $p->id)
            ->where('vaccine_type', 'influenza')
            ->where('administered_date', '>=', $seasonStart->toDateString())
            ->orderByDesc('administered_date')->first();
        return [
            'satisfied' => (bool) $shot,
            'last_satisfied_date' => $shot?->administered_date?->toDateString(),
            'next_due_date' => $seasonStart->copy()->addYear()->toDateString(),
            'reason_open' => $shot ? null : 'No flu shot this season',
        ];
    }

    private function pneumococcal(Participant $p): array
    {
        if (! $p->dob || $p->dob->diffInYears(now()) < 65) {
            // Not yet indicated.
            return ['satisfied' => true, 'last_satisfied_date' => null, 'next_due_date' => null, 'reason_open' => null];
        }
        $shot = Immunization::where('participant_id', $p->id)
            ->where(function ($q) {
                $q->where('vaccine_type', 'like', 'pneumococcal_%');
            })
            ->orderByDesc('administered_date')->first();
        return [
            'satisfied' => (bool) $shot,
            'last_satisfied_date' => $shot?->administered_date?->toDateString(),
            'next_due_date' => null,
            'reason_open' => $shot ? null : 'No pneumococcal vaccine on record (age ≥65)',
        ];
    }

    private function ageGated(Participant $p, int $minAge, int $maxAge, ?string $sex, int $intervalDays, string $noteSearch): array
    {
        if (! $p->dob) {
            return ['satisfied' => false, 'last_satisfied_date' => null, 'next_due_date' => null, 'reason_open' => 'DOB missing'];
        }
        $age = $p->dob->diffInYears(now());
        if ($age < $minAge || $age > $maxAge || ($sex && strtolower($p->gender ?? '') !== $sex)) {
            return ['satisfied' => true, 'last_satisfied_date' => null, 'next_due_date' => null, 'reason_open' => null];
        }
        $last = ClinicalNote::where('participant_id', $p->id)
            ->where(function ($q) use ($noteSearch) {
                $q->where('assessment', 'ilike', '%' . $noteSearch . '%')
                  ->orWhere('plan', 'ilike', '%' . $noteSearch . '%')
                  ->orWhere('subjective', 'ilike', '%' . $noteSearch . '%');
            })
            ->orderByDesc('visit_date')->first();
        $cutoff = now()->subDays($intervalDays);
        $satisfied = $last && $last->visit_date && $last->visit_date->gte($cutoff);
        return [
            'satisfied' => $satisfied,
            'last_satisfied_date' => $last?->visit_date?->toDateString(),
            'next_due_date' => $last?->visit_date?->copy()->addDays($intervalDays)->toDateString(),
            'reason_open' => $satisfied ? null : "No documented {$noteSearch} in last " . ($intervalDays / 365) . 'y',
        ];
    }

    private function mammogram(Participant $p): array
    {
        return $this->ageGated($p, 40, 130, 'female', 2 * 365, 'mammogram');
    }

    private function diabetesMeasure(Participant $p, string $key, int $intervalDays): array
    {
        $isDm = Problem::where('participant_id', $p->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where('icd10_code', 'like', 'E10%')
                  ->orWhere('icd10_code', 'like', 'E11%')
                  ->orWhere('icd10_description', 'ilike', '%diabetes%');
            })->exists();
        if (! $isDm) {
            return ['satisfied' => true, 'last_satisfied_date' => null, 'next_due_date' => null, 'reason_open' => null];
        }
        // Look for keyword in recent notes.
        $search = $key === 'a1c' ? 'a1c' : 'eye exam';
        $last = ClinicalNote::where('participant_id', $p->id)
            ->where(function ($q) use ($search) {
                $q->where('assessment', 'ilike', '%' . $search . '%')
                  ->orWhere('plan', 'ilike', '%' . $search . '%')
                  ->orWhere('objective', 'ilike', '%' . $search . '%');
            })
            ->orderByDesc('visit_date')->first();
        $cutoff = now()->subDays($intervalDays);
        $satisfied = $last && $last->visit_date && $last->visit_date->gte($cutoff);
        return [
            'satisfied' => $satisfied,
            'last_satisfied_date' => $last?->visit_date?->toDateString(),
            'next_due_date' => $last?->visit_date?->copy()->addDays($intervalDays)->toDateString(),
            'reason_open' => $satisfied ? null : "Diabetic without recent {$search} documented",
        ];
    }
}
