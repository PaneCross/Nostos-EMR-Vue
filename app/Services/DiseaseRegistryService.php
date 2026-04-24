<?php

// ─── DiseaseRegistryService ──────────────────────────────────────────────────
// Phase G2. Returns cohort rosters for 3 disease registries driven by active
// ICD-10 problems. Each roster includes the key monitoring datapoints
// clinicians want at-a-glance.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\ClinicalNote;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Problem;
use App\Models\Vital;

class DiseaseRegistryService
{
    public const DEFINITIONS = [
        'diabetes' => [
            'label'    => 'Diabetes',
            'icd10'    => ['E10%', 'E11%', 'E13%'],
            'keyword'  => 'diabetes',
        ],
        'chf' => [
            'label'    => 'Congestive Heart Failure',
            'icd10'    => ['I50%'],
            'keyword'  => 'heart failure',
        ],
        'copd' => [
            'label'    => 'COPD',
            'icd10'    => ['J44%'],
            'keyword'  => 'copd',
        ],
    ];

    public function cohort(int $tenantId, string $key): array
    {
        abort_unless(isset(self::DEFINITIONS[$key]), 422, 'Unknown registry.');
        $def = self::DEFINITIONS[$key];

        $participantIds = Problem::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where(function ($q) use ($def) {
                foreach ($def['icd10'] as $prefix) {
                    $q->orWhere('icd10_code', 'like', $prefix);
                }
                $q->orWhere('icd10_description', 'ilike', '%' . $def['keyword'] . '%');
            })
            ->pluck('participant_id')->unique()->values();

        $rows = Participant::whereIn('id', $participantIds)
            ->select(['id', 'mrn', 'first_name', 'last_name', 'dob'])
            ->get()
            ->map(fn (Participant $p) => array_merge([
                'id' => $p->id, 'mrn' => $p->mrn,
                'name' => $p->first_name . ' ' . $p->last_name,
                'age' => $p->dob ? (int) $p->dob->diffInYears(now()) : null,
            ], match ($key) {
                'diabetes' => $this->diabetesRow($p),
                'chf'      => $this->chfRow($p),
                'copd'     => $this->copdRow($p),
            }));

        return ['label' => $def['label'], 'count' => $rows->count(), 'rows' => $rows->values()];
    }

    private function diabetesRow(Participant $p): array
    {
        $lastA1c = ClinicalNote::where('participant_id', $p->id)
            ->where(function ($q) { $q->where('objective', 'ilike', '%a1c%')->orWhere('assessment', 'ilike', '%a1c%'); })
            ->orderByDesc('visit_date')->value('visit_date');
        $lastEye = ClinicalNote::where('participant_id', $p->id)
            ->where('plan', 'ilike', '%eye exam%')
            ->orderByDesc('visit_date')->value('visit_date');
        $lastFoot = ClinicalNote::where('participant_id', $p->id)
            ->where('objective', 'ilike', '%foot exam%')
            ->orderByDesc('visit_date')->value('visit_date');
        $meds = Medication::where('participant_id', $p->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where('drug_name', 'ilike', '%insulin%')
                  ->orWhere('drug_name', 'ilike', '%metformin%')
                  ->orWhere('drug_name', 'ilike', '%glipizide%')
                  ->orWhere('drug_name', 'ilike', '%glyburide%');
            })->pluck('drug_name')->all();
        return [
            'last_a1c_date'      => $lastA1c?->toDateString(),
            'last_eye_exam_date' => $lastEye?->toDateString(),
            'last_foot_exam_date'=> $lastFoot?->toDateString(),
            'active_meds'        => $meds,
        ];
    }

    private function chfRow(Participant $p): array
    {
        $lastEcho = ClinicalNote::where('participant_id', $p->id)
            ->where(function ($q) { $q->where('objective', 'ilike', '%echo%')->orWhere('plan', 'ilike', '%echo%'); })
            ->orderByDesc('visit_date')->value('visit_date');
        $latestVital = Vital::where('participant_id', $p->id)->orderByDesc('recorded_at')->first();
        $meds = Medication::where('participant_id', $p->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where('drug_name', 'ilike', '%furosemide%')
                  ->orWhere('drug_name', 'ilike', '%lasix%')
                  ->orWhere('drug_name', 'ilike', '%spironolactone%')
                  ->orWhere('drug_name', 'ilike', '%metoprolol%')
                  ->orWhere('drug_name', 'ilike', '%carvedilol%');
            })->pluck('drug_name')->all();
        return [
            'last_echo_date' => $lastEcho?->toDateString(),
            'latest_weight_lbs' => $latestVital?->weight_lbs,
            'active_meds' => $meds,
        ];
    }

    private function copdRow(Participant $p): array
    {
        $lastPft = ClinicalNote::where('participant_id', $p->id)
            ->where(function ($q) { $q->where('objective', 'ilike', '%pft%')->orWhere('plan', 'ilike', '%pulmonary function%'); })
            ->orderByDesc('visit_date')->value('visit_date');
        $inhalers = Medication::where('participant_id', $p->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where('drug_name', 'ilike', '%albuterol%')
                  ->orWhere('drug_name', 'ilike', '%tiotropium%')
                  ->orWhere('drug_name', 'ilike', '%fluticasone%')
                  ->orWhere('drug_name', 'ilike', '%budesonide%')
                  ->orWhere('drug_name', 'ilike', '%spiriva%');
            })->pluck('drug_name')->all();
        return [
            'last_pft_date' => $lastPft?->toDateString(),
            'inhalers'      => $inhalers,
        ];
    }

    /** CSV export of a cohort. */
    public function toCsv(int $tenantId, string $key): string
    {
        $cohort = $this->cohort($tenantId, $key);
        if (empty($cohort['rows'])) return '';

        $first = $cohort['rows']->first();
        $headers = array_keys($first);
        $buf = implode(',', $headers) . "\n";
        foreach ($cohort['rows'] as $row) {
            $buf .= implode(',', array_map(fn ($v) => is_array($v) ? '"' . str_replace('"', "'", implode(';', $v)) . '"' : '"' . str_replace('"', "'", (string) $v) . '"', $row)) . "\n";
        }
        return $buf;
    }
}
