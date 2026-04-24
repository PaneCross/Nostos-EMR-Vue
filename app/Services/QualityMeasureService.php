<?php

// ─── QualityMeasureService ───────────────────────────────────────────────────
// Phase G3. Computes nightly numerator/denominator per HEDIS + CMS Stars
// measure for a tenant. Seeded measures are defined in QualityMeasureSeeder.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\CareGap;
use App\Models\ClinicalNote;
use App\Models\Immunization;
use App\Models\Incident;
use App\Models\Participant;
use App\Models\Problem;
use App\Models\QualityMeasureSnapshot;

class QualityMeasureService
{
    /**
     * Compute every seeded measure for a tenant and persist a snapshot row.
     * @return array<int, QualityMeasureSnapshot>
     */
    public function computeAll(int $tenantId): array
    {
        $out = [];
        foreach (array_keys($this->computers()) as $id) {
            $out[] = $this->computeOne($tenantId, $id);
        }
        return $out;
    }

    public function computeOne(int $tenantId, string $measureId): QualityMeasureSnapshot
    {
        $fn = $this->computers()[$measureId] ?? null;
        abort_unless($fn, 422, "Unknown measure {$measureId}");

        [$num, $den] = $fn($tenantId);
        $rate = $den > 0 ? round(100 * $num / $den, 2) : null;

        return QualityMeasureSnapshot::create([
            'tenant_id'   => $tenantId,
            'measure_id'  => $measureId,
            'numerator'   => $num,
            'denominator' => $den,
            'rate_pct'    => $rate,
            'computed_at' => now(),
        ]);
    }

    /**
     * Map of measure_id → closure returning [numerator, denominator].
     * @return array<string, \Closure>
     */
    private function computers(): array
    {
        return [
            'FLU'  => fn ($t) => [
                Participant::where('tenant_id', $t)->where('enrollment_status', 'enrolled')
                    ->whereExists(function ($q) {
                        $q->selectRaw(1)->from('emr_immunizations')
                          ->whereColumn('emr_immunizations.participant_id', 'emr_participants.id')
                          ->where('vaccine_type', 'influenza')
                          ->where('administered_date', '>=', now()->subMonths(12));
                    })->count(),
                Participant::where('tenant_id', $t)->where('enrollment_status', 'enrolled')->count(),
            ],
            'PNE'  => fn ($t) => [
                Participant::where('tenant_id', $t)->where('enrollment_status', 'enrolled')
                    ->whereDate('dob', '<=', now()->subYears(65))
                    ->whereExists(function ($q) {
                        $q->selectRaw(1)->from('emr_immunizations')
                          ->whereColumn('emr_immunizations.participant_id', 'emr_participants.id')
                          ->where('vaccine_type', 'like', 'pneumococcal_%');
                    })->count(),
                Participant::where('tenant_id', $t)->where('enrollment_status', 'enrolled')
                    ->whereDate('dob', '<=', now()->subYears(65))->count(),
            ],
            'PCV'  => fn ($t) => [
                // Annual PCP visit
                Participant::where('tenant_id', $t)->where('enrollment_status', 'enrolled')
                    ->whereExists(function ($q) {
                        $q->selectRaw(1)->from('emr_clinical_notes')
                          ->whereColumn('emr_clinical_notes.participant_id', 'emr_participants.id')
                          ->whereIn('note_type', ['soap', 'progress_nursing'])
                          ->where('visit_date', '>=', now()->subDays(365)->toDateString());
                    })->count(),
                Participant::where('tenant_id', $t)->where('enrollment_status', 'enrolled')->count(),
            ],
            'A1C'  => fn ($t) => $this->diabeticCareGapMetric($t, 'a1c'),
            'DEE'  => fn ($t) => $this->diabeticCareGapMetric($t, 'diabetic_eye_exam'),
            'FALL' => fn ($t) => [
                // "No injury falls in last 90d" numerator
                Participant::where('tenant_id', $t)->where('enrollment_status', 'enrolled')
                    ->whereNotExists(function ($q) {
                        $q->selectRaw(1)->from('emr_incidents')
                          ->whereColumn('emr_incidents.participant_id', 'emr_participants.id')
                          ->where('incident_type', 'fall')
                          ->where('injuries_sustained', true)
                          ->where('occurred_at', '>=', now()->subDays(90));
                    })->count(),
                Participant::where('tenant_id', $t)->where('enrollment_status', 'enrolled')->count(),
            ],
            'NPP'  => fn ($t) => [
                Participant::where('tenant_id', $t)->where('enrollment_status', 'enrolled')
                    ->whereExists(function ($q) {
                        $q->selectRaw(1)->from('emr_consent_records')
                          ->whereColumn('emr_consent_records.participant_id', 'emr_participants.id')
                          ->where('consent_type', 'npp_acknowledgment')
                          ->where('status', 'acknowledged');
                    })->count(),
                Participant::where('tenant_id', $t)->where('enrollment_status', 'enrolled')->count(),
            ],
            'HOS'  => fn ($t) => [
                // Participants without a hospitalization in last 90d (lower is worse — so numerator = "not hospitalized")
                Participant::where('tenant_id', $t)->where('enrollment_status', 'enrolled')
                    ->whereNotExists(function ($q) {
                        $q->selectRaw(1)->from('emr_incidents')
                          ->whereColumn('emr_incidents.participant_id', 'emr_participants.id')
                          ->where('incident_type', 'hospitalization')
                          ->where('occurred_at', '>=', now()->subDays(90));
                    })->count(),
                Participant::where('tenant_id', $t)->where('enrollment_status', 'enrolled')->count(),
            ],
            'AD'   => fn ($t) => [
                Participant::where('tenant_id', $t)->where('enrollment_status', 'enrolled')
                    ->whereNotNull('advance_directive_status')
                    ->where('advance_directive_status', '!=', 'none')->count(),
                Participant::where('tenant_id', $t)->where('enrollment_status', 'enrolled')->count(),
            ],
        ];
    }

    private function diabeticCareGapMetric(int $tenantId, string $measure): array
    {
        $dmIds = Problem::where('tenant_id', $tenantId)->where('status', 'active')
            ->where(function ($q) {
                $q->where('icd10_code', 'like', 'E10%')
                  ->orWhere('icd10_code', 'like', 'E11%');
            })->pluck('participant_id')->unique();
        $den = $dmIds->count();
        $num = CareGap::where('tenant_id', $tenantId)->whereIn('participant_id', $dmIds)
            ->where('measure', $measure)->where('satisfied', true)->count();
        return [$num, $den];
    }
}
