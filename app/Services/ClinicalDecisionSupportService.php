<?php

// ─── ClinicalDecisionSupportService ──────────────────────────────────────────
// Phase 15.6. Three MVP CDS rules:
//   1. Fall risk auto-flag : high Morse fall-scale score → create alert +
//      (optional) participant flag
//   2. Sepsis screening trigger : combination of vitals + recent fever →
//      warning alert to primary_care
//   3. Anticoagulant + NSAID interaction guardrail : alert if the two are
//      concurrently active
//
// Callable standalone or on model save hooks. Returns a structured
// {findings, alertsCreated} array.
//
// Uses the existing Alert infrastructure (severity + target_departments +
// metadata). Dedup via a 24h window + grouped metadata key.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\Alert;
use App\Models\Assessment;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Vital;

class ClinicalDecisionSupportService
{
    public function evaluate(Participant $participant): array
    {
        $findings = [];
        $alertsCreated = 0;

        foreach (['fall_risk', 'sepsis_screen', 'anticoag_nsaid'] as $rule) {
            $finding = match ($rule) {
                'fall_risk'      => $this->evalFallRisk($participant),
                'sepsis_screen'  => $this->evalSepsisScreen($participant),
                'anticoag_nsaid' => $this->evalAnticoagNsaid($participant),
            };
            if ($finding['triggered']) {
                $findings[] = array_merge(['rule' => $rule], $finding);
                if ($this->createAlertIfNotDuplicate($participant, $rule, $finding)) {
                    $alertsCreated++;
                }
            }
        }

        return [
            'participant_id' => $participant->id,
            'findings'       => $findings,
            'alerts_created' => $alertsCreated,
        ];
    }

    // ── Rule 1: Fall risk ───────────────────────────────────────────────────
    private function evalFallRisk(Participant $participant): array
    {
        $latest = Assessment::where('participant_id', $participant->id)
            ->where('assessment_type', 'fall_risk_morse')
            ->orderByDesc('created_at')->first();

        if (! $latest) return ['triggered' => false];

        $total = (int) ($latest->total_score ?? 0);
        if ($total < 45) return ['triggered' => false, 'score' => $total];

        return [
            'triggered'     => true,
            'score'         => $total,
            'severity'      => $total >= 65 ? 'critical' : 'warning',
            'message'       => "Morse Fall Scale score {$total} (high risk). Implement fall-prevention interventions.",
        ];
    }

    // ── Rule 2: Sepsis screen ───────────────────────────────────────────────
    private function evalSepsisScreen(Participant $participant): array
    {
        $recent = Vital::where('participant_id', $participant->id)
            ->where('recorded_at', '>=', now()->subHours(24))
            ->orderByDesc('recorded_at')->first();
        if (! $recent) return ['triggered' => false];

        $qsofa = 0;
        // qSOFA quickSOFA thresholds: RR ≥22, SBP ≤100, altered mental status
        if (($recent->respiratory_rate ?? 0) >= 22)   $qsofa++;
        if (($recent->systolic_bp ?? 999) <= 100)     $qsofa++;
        // Altered mental status stub : we don't capture it structurally yet
        $highFever = ($recent->temperature_f ?? 0) >= 101.5;

        $triggered = $qsofa >= 2 || ($qsofa >= 1 && $highFever);
        if (! $triggered) return ['triggered' => false, 'qsofa' => $qsofa];

        return [
            'triggered' => true,
            'qsofa'     => $qsofa,
            'severity'  => 'critical',
            'message'   => "Sepsis screen positive: qSOFA={$qsofa}"
                           . ($highFever ? ', fever' : '') . '. Consider urgent evaluation.',
        ];
    }

    // ── Rule 3: Anticoag + NSAID ────────────────────────────────────────────
    private function evalAnticoagNsaid(Participant $participant): array
    {
        $anticoag = ['warfarin', 'apixaban', 'rivaroxaban', 'dabigatran', 'edoxaban', 'heparin'];
        $nsaid    = ['ibuprofen', 'naproxen', 'diclofenac', 'celecoxib', 'meloxicam', 'ketorolac', 'indomethacin'];

        $active = Medication::where('participant_id', $participant->id)
            ->whereIn('status', ['active', 'prn'])
            ->get(['id', 'drug_name']);

        $matches = [
            'anticoag' => $active->filter(fn ($m) => $this->nameMatches($m->drug_name, $anticoag)),
            'nsaid'    => $active->filter(fn ($m) => $this->nameMatches($m->drug_name, $nsaid)),
        ];
        if ($matches['anticoag']->isEmpty() || $matches['nsaid']->isEmpty()) {
            return ['triggered' => false];
        }

        $ac = $matches['anticoag']->pluck('drug_name')->unique()->values()->all();
        $ns = $matches['nsaid']->pluck('drug_name')->unique()->values()->all();

        return [
            'triggered' => true,
            'anticoag'  => $ac,
            'nsaid'     => $ns,
            'severity'  => 'warning',
            'message'   => 'Anticoagulant + NSAID concurrent: '
                           . implode(', ', $ac) . ' with ' . implode(', ', $ns)
                           . '. Increased bleeding risk; consider GI prophylaxis.',
        ];
    }

    private function nameMatches(?string $drugName, array $ingredients): bool
    {
        if (! $drugName) return false;
        $n = strtolower($drugName);
        foreach ($ingredients as $i) {
            if (str_contains($n, $i)) return true;
        }
        return false;
    }

    private function createAlertIfNotDuplicate(Participant $p, string $rule, array $finding): bool
    {
        $dupe = Alert::where('tenant_id', $p->tenant_id)
            ->where('alert_type', "cds_{$rule}")
            ->where('created_at', '>=', now()->subHours(24))
            ->whereRaw("(metadata->>'participant_id')::int = ?", [$p->id])
            ->exists();
        if ($dupe) return false;

        Alert::create([
            'tenant_id'          => $p->tenant_id,
            'participant_id'     => $p->id,
            'alert_type'         => "cds_{$rule}",
            'source_module'      => 'cds',
            'severity'           => $finding['severity'] ?? 'warning',
            'title'              => 'Clinical decision support: ' . str_replace('_', ' ', $rule),
            'message'            => $finding['message'],
            'target_departments' => ['primary_care', 'home_care'],
            'metadata'           => [
                'participant_id' => $p->id,
                'rule'           => $rule,
                'finding'        => $finding,
            ],
            'created_by_system'  => true,
        ]);
        return true;
    }
}
