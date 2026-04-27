<?php

// ─── LevelIiReportingService ──────────────────────────────────────────────────
// Aggregates a tenant's CMS Level I / Level II indicators for a calendar
// quarter and produces a CSV artifact + snapshot row.
//
// Indicator → source mapping:
//   Mortality (all)          Participant.disenrollment_type='death'
//   Hospital admissions      Incident.incident_type='hospitalization'
//   ER visits                Incident.incident_type='er_visit'
//   Falls (total)            Incident.incident_type='fall'
//   Falls with injury        Incident.incident_type='fall' AND injuries_sustained=true
//   Pressure injuries (new)  WoundRecord.wound_type='pressure_injury' AND start_date in period
//   Pressure inj. stage 2+   wound_type='pressure_injury' AND pressure_injury_stage IN ('stage_2','stage_3','stage_4','unstageable','deep_tissue_injury')
//   Pressure inj. critical   pressure_injury_stage IN WoundRecord::CRITICAL_STAGES
//   Flu vaccinations         Immunization.vaccine_type='influenza' administered in period
//   Pneumococcal vaccinations Immunization.vaccine_type LIKE 'pneumococcal_%'
//   Burns                    Incident.incident_type='injury' AND description/injury ILIKE '%burn%'
//   Infectious disease       Incident.incident_type='infection'
//
// Output format: flat CSV with one indicator per row. Each row carries a
// context column (denominator or subcategory) for auditor traceability.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Immunization;
use App\Models\Incident;
use App\Models\LevelIiSubmission;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WoundRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LevelIiReportingService
{
    public const VALID_QUARTERS = [1, 2, 3, 4];

    /**
     * Generate (or regenerate) a quarterly submission for a tenant.
     * Idempotent per (tenant, year, quarter) : keeps the submitted stamp.
     */
    public function generate(Tenant $tenant, int $year, int $quarter, User $actor): LevelIiSubmission
    {
        if (! in_array($quarter, self::VALID_QUARTERS, true)) {
            throw new \InvalidArgumentException("Invalid quarter: {$quarter}");
        }

        return DB::transaction(function () use ($tenant, $year, $quarter, $actor) {
            $submission = LevelIiSubmission::firstOrNew([
                'tenant_id' => $tenant->id,
                'year'      => $year,
                'quarter'   => $quarter,
            ]);

            // Compile BEFORE first save so indicators_snapshot (NOT NULL) is populated.
            $indicators = $this->compileIndicators($tenant, $submission->periodStart(), $submission->periodEnd());
            [$path, $size] = $this->renderCsv($tenant, $year, $quarter, $indicators);

            $submission->generated_at         = now();
            $submission->generated_by_user_id = $actor->id;
            $submission->indicators_snapshot  = $indicators;
            $submission->csv_path             = $path;
            $submission->csv_size_bytes       = $size;
            $submission->save();

            AuditLog::record(
                action:       'level_ii_submission.generated',
                tenantId:     $tenant->id,
                userId:       $actor->id,
                resourceType: 'level_ii_submission',
                resourceId:   $submission->id,
                description:  "Level I/II submission generated for Q{$quarter} {$year}",
            );

            return $submission->fresh();
        });
    }

    /**
     * Honest-labeling stamp: "Mark CMS Submitted". Records upload timestamp
     * + actor to audit log : does NOT actually transmit to CMS HPMS.
     */
    public function markCmsSubmitted(LevelIiSubmission $submission, User $actor, ?string $notes = null): LevelIiSubmission
    {
        $submission->update([
            'marked_cms_submitted_at'          => now(),
            'marked_cms_submitted_by_user_id'  => $actor->id,
            'marked_cms_submitted_notes'       => $notes,
        ]);

        AuditLog::record(
            action:       'level_ii_submission.marked_cms_submitted',
            tenantId:     $submission->tenant_id,
            userId:       $actor->id,
            resourceType: 'level_ii_submission',
            resourceId:   $submission->id,
            description:  "Marked as CMS-submitted (Q{$submission->quarter} {$submission->year}) : manual flag, no automated transmission.",
        );

        return $submission->fresh();
    }

    // ── Indicator compilation (pure functions : testable) ───────────────────

    /**
     * Compile every Level I/II indicator for a tenant over a period.
     * Returns a flat associative array the CSV renderer + JSON snapshot share.
     */
    public function compileIndicators(Tenant $tenant, Carbon $start, Carbon $end): array
    {
        $tid = $tenant->id;

        $enrolledCensus = $this->averageDailyEnrolledCensus($tid, $start, $end);

        return [
            'period_start' => $start->toDateString(),
            'period_end'   => $end->toDateString(),
            'avg_daily_enrolled_census' => $enrolledCensus,

            // Mortality
            'deaths' => $this->countDeaths($tid, $start, $end),

            // Hospital / ER
            'hospital_admissions' => $this->countIncidents($tid, 'hospitalization', $start, $end),
            'er_visits'           => $this->countIncidents($tid, 'er_visit', $start, $end),

            // Falls
            'falls_total'         => $this->countIncidents($tid, 'fall', $start, $end),
            'falls_with_injury'   => $this->countFallsWithInjury($tid, $start, $end),

            // Pressure injuries
            'pressure_injuries_new'        => $this->countPressureInjuriesNew($tid, $start, $end),
            'pressure_injuries_stage_2p'   => $this->countPressureInjuriesStage2p($tid, $start, $end),
            'pressure_injuries_critical'   => $this->countPressureInjuriesCritical($tid, $start, $end),

            // Immunizations
            'flu_vaccinations_given'       => $this->countImmunizations($tid, 'influenza', $start, $end),
            'flu_vaccination_rate_pct'     => $this->vaccinationRate($tid, 'influenza', $start, $end, $enrolledCensus),
            'pneumo_vaccinations_given'    => $this->countPneumoImmunizations($tid, $start, $end),
            'pneumo_vaccination_rate_pct'  => $this->pneumoVaccinationRate($tid, $start, $end, $enrolledCensus),

            // Other adverse events
            'burns'                 => $this->countBurns($tid, $start, $end),
            'infectious_disease'    => $this->countIncidents($tid, 'infection', $start, $end),
            'medication_errors'     => $this->countIncidents($tid, 'medication_error', $start, $end),
            'elopements'            => $this->countIncidents($tid, 'elopement', $start, $end),
            'abuse_neglect'         => $this->countIncidents($tid, 'abuse_neglect', $start, $end),
            'unexpected_deaths'     => $this->countIncidents($tid, 'unexpected_death', $start, $end),
        ];
    }

    // ── Aggregators ──────────────────────────────────────────────────────────

    public function countDeaths(int $tenantId, Carbon $start, Carbon $end): int
    {
        return Participant::where('tenant_id', $tenantId)
            ->where('disenrollment_type', 'death')
            ->whereBetween('disenrollment_date', [$start->toDateString(), $end->toDateString()])
            ->count();
    }

    public function countIncidents(int $tenantId, string $type, Carbon $start, Carbon $end): int
    {
        return Incident::where('tenant_id', $tenantId)
            ->where('incident_type', $type)
            ->whereBetween('occurred_at', [$start, $end])
            ->count();
    }

    public function countFallsWithInjury(int $tenantId, Carbon $start, Carbon $end): int
    {
        return Incident::where('tenant_id', $tenantId)
            ->where('incident_type', 'fall')
            ->where('injuries_sustained', true)
            ->whereBetween('occurred_at', [$start, $end])
            ->count();
    }

    public function countPressureInjuriesNew(int $tenantId, Carbon $start, Carbon $end): int
    {
        return WoundRecord::where('tenant_id', $tenantId)
            ->where('wound_type', 'pressure_injury')
            ->whereBetween('first_identified_date', [$start->toDateString(), $end->toDateString()])
            ->count();
    }

    public function countPressureInjuriesStage2p(int $tenantId, Carbon $start, Carbon $end): int
    {
        return WoundRecord::where('tenant_id', $tenantId)
            ->where('wound_type', 'pressure_injury')
            ->whereIn('pressure_injury_stage', ['stage_2', 'stage_3', 'stage_4', 'unstageable', 'deep_tissue_injury'])
            ->whereBetween('first_identified_date', [$start->toDateString(), $end->toDateString()])
            ->count();
    }

    public function countPressureInjuriesCritical(int $tenantId, Carbon $start, Carbon $end): int
    {
        return WoundRecord::where('tenant_id', $tenantId)
            ->where('wound_type', 'pressure_injury')
            ->whereIn('pressure_injury_stage', WoundRecord::CRITICAL_STAGES)
            ->whereBetween('first_identified_date', [$start->toDateString(), $end->toDateString()])
            ->count();
    }

    public function countImmunizations(int $tenantId, string $vaccineType, Carbon $start, Carbon $end): int
    {
        return Immunization::where('tenant_id', $tenantId)
            ->where('vaccine_type', $vaccineType)
            ->whereBetween('administered_date', [$start->toDateString(), $end->toDateString()])
            ->count();
    }

    public function countPneumoImmunizations(int $tenantId, Carbon $start, Carbon $end): int
    {
        return Immunization::where('tenant_id', $tenantId)
            ->where('vaccine_type', 'LIKE', 'pneumococcal_%')
            ->whereBetween('administered_date', [$start->toDateString(), $end->toDateString()])
            ->count();
    }

    public function vaccinationRate(int $tenantId, string $vaccineType, Carbon $start, Carbon $end, float $census): ?float
    {
        if ($census <= 0) return null;
        $count = $this->countImmunizations($tenantId, $vaccineType, $start, $end);
        return round(($count / $census) * 100, 1);
    }

    public function pneumoVaccinationRate(int $tenantId, Carbon $start, Carbon $end, float $census): ?float
    {
        if ($census <= 0) return null;
        $count = $this->countPneumoImmunizations($tenantId, $start, $end);
        return round(($count / $census) * 100, 1);
    }

    public function countBurns(int $tenantId, Carbon $start, Carbon $end): int
    {
        // No dedicated 'burn' incident type : filter 'injury' incidents by narrative.
        // MVP approximation; long-term a dedicated 'burn' type is a Phase 13 polish item.
        return Incident::where('tenant_id', $tenantId)
            ->where('incident_type', 'injury')
            ->where(function ($q) {
                $q->where('description', 'ILIKE', '%burn%')
                  ->orWhere('injury_description', 'ILIKE', '%burn%');
            })
            ->whereBetween('occurred_at', [$start, $end])
            ->count();
    }

    /**
     * Approximate average daily enrolled census over the period.
     * Used as the denominator for vaccination rates.
     * Calculation: midpoint-of-period count of enrolled participants.
     * This is a simplified proxy : true ADC requires daily enrollment snapshots
     * (deferred as Phase 15 "member months" work).
     */
    public function averageDailyEnrolledCensus(int $tenantId, Carbon $start, Carbon $end): float
    {
        $midpoint = $start->copy()->addSeconds((int) ($start->diffInSeconds($end) / 2));

        return (float) Participant::where('tenant_id', $tenantId)
            ->where(function ($q) use ($midpoint) {
                // Enrolled BEFORE midpoint, and either still enrolled or disenrolled AFTER midpoint.
                $q->whereNotNull('enrollment_date')
                  ->where('enrollment_date', '<=', $midpoint->toDateString())
                  ->where(function ($qq) use ($midpoint) {
                      $qq->where('enrollment_status', 'enrolled')
                         ->orWhere(function ($qqq) use ($midpoint) {
                             $qqq->whereNotNull('disenrollment_date')
                                 ->where('disenrollment_date', '>', $midpoint->toDateString());
                         });
                  });
            })
            ->count();
    }

    // ── CSV rendering ────────────────────────────────────────────────────────

    /**
     * @return array{0:string,1:int} [filePath, sizeBytes]
     */
    private function renderCsv(Tenant $tenant, int $year, int $quarter, array $indicators): array
    {
        $rows = [
            ['indicator', 'value', 'context', 'cfr_reference'],
            ['Reporting Period Start',       $indicators['period_start'],                  '',                                             ''],
            ['Reporting Period End',         $indicators['period_end'],                    '',                                             ''],
            ['Avg Daily Enrolled Census',    $indicators['avg_daily_enrolled_census'],     'midpoint-of-period proxy',                     ''],
            ['Deaths (all causes)',          $indicators['deaths'],                        'from disenrollment_type=death',                '§460.160(b)'],
            ['Hospital Admissions',          $indicators['hospital_admissions'],           'Incident.incident_type=hospitalization',       '§460.200(a)'],
            ['ER Visits',                    $indicators['er_visits'],                     'Incident.incident_type=er_visit',              '§460.200(a)'],
            ['Falls (total)',                $indicators['falls_total'],                   'Incident.incident_type=fall',                  '§460.200(a)'],
            ['Falls with Injury',            $indicators['falls_with_injury'],             'incident_type=fall AND injuries_sustained',    '§460.200(a)'],
            ['Pressure Injuries (new)',      $indicators['pressure_injuries_new'],         'new pressure injury records in period',        '§460.200(a)'],
            ['Pressure Injuries Stage 2+',   $indicators['pressure_injuries_stage_2p'],    'subset of new, stage 2 or worse',              '§460.200(a)'],
            ['Pressure Injuries Critical',   $indicators['pressure_injuries_critical'],    'stage 3/4/unstageable/DTI',                    '§460.200(a)'],
            ['Flu Vaccinations Given',       $indicators['flu_vaccinations_given'],        'Immunization.vaccine_type=influenza',          'CMS PACE Quality'],
            ['Flu Vaccination Rate (%)',     $indicators['flu_vaccination_rate_pct'] ?? 'N/A', 'given / avg daily census',                 'CMS PACE Quality'],
            ['Pneumococcal Vaccinations',    $indicators['pneumo_vaccinations_given'],     'Immunization.vaccine_type LIKE pneumococcal_%', 'CMS PACE Quality'],
            ['Pneumo Vaccination Rate (%)',  $indicators['pneumo_vaccination_rate_pct'] ?? 'N/A', 'given / avg daily census',              'CMS PACE Quality'],
            ['Burns',                        $indicators['burns'],                         'incident_type=injury with "burn" narrative',   '§460.200(a)'],
            ['Infectious Disease Events',    $indicators['infectious_disease'],            'Incident.incident_type=infection',             '§460.200(a)'],
            ['Medication Errors',            $indicators['medication_errors'],             'Incident.incident_type=medication_error',      '§460.200(a)'],
            ['Elopements',                   $indicators['elopements'],                    'Incident.incident_type=elopement',             '§460.200(a)'],
            ['Abuse / Neglect Reports',      $indicators['abuse_neglect'],                 'Incident.incident_type=abuse_neglect',         '§460.200(a)'],
            ['Unexpected Deaths',            $indicators['unexpected_deaths'],             'Incident.incident_type=unexpected_death',      '§460.200(a)'],
        ];

        $csv = '';
        foreach ($rows as $r) {
            $csv .= implode(',', array_map(
                fn ($v) => is_string($v) && str_contains($v, ',')
                    ? '"' . str_replace('"', '""', $v) . '"'
                    : (string) $v,
                $r,
            )) . "\n";
        }

        $filename = sprintf(
            'level-ii/%d/TENANT-%d-LEVEL-II-%d-Q%d-%s.csv',
            $tenant->id,
            $tenant->id,
            $year,
            $quarter,
            now()->format('Ymd-His'),
        );
        Storage::disk('local')->put($filename, $csv);

        return [$filename, strlen($csv)];
    }
}
