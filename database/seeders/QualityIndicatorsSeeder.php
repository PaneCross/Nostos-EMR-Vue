<?php

// ─── QualityIndicatorsSeeder ──────────────────────────────────────────────────
// Seeds realistic incident + wound + immunization volumes across the past 4
// calendar quarters so the Level I/II reporting dashboard shows meaningful
// numbers right after demo-data setup.
//
// Phase 3 (MVP roadmap).
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\Immunization;
use App\Models\Incident;
use App\Models\Participant;
use App\Models\WoundRecord;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class QualityIndicatorsSeeder extends Seeder
{
    public function run(): void
    {
        $enrolled = Participant::where('enrollment_status', 'enrolled')
            ->with('tenant:id')
            ->get();

        if ($enrolled->isEmpty()) {
            $this->command?->warn('No enrolled participants — skipping Level I/II quality indicator seed.');
            return;
        }

        // Group by tenant so seeded events never cross tenant boundaries.
        $byTenant = $enrolled->groupBy('tenant_id');

        $created = [
            'falls' => 0, 'hospitalizations' => 0, 'er_visits' => 0,
            'med_errors' => 0, 'infections' => 0, 'burns' => 0,
            'elopements' => 0, 'immunizations' => 0,
        ];

        foreach ($byTenant as $tenantId => $participants) {
            $reporter = User::where('tenant_id', $tenantId)
                ->whereIn('department', ['primary_care', 'qa_compliance', 'it_admin'])
                ->value('id')
                ?? User::where('tenant_id', $tenantId)->value('id');
            if (! $reporter) continue;

            // Iterate the past 4 calendar quarters.
            for ($qOffset = 0; $qOffset <= 3; $qOffset++) {
                $periodStart = $this->quarterStart($qOffset);
                $periodEnd   = $periodStart->copy()->addMonths(3)->subDay();
                if ($periodEnd->isFuture()) $periodEnd = now()->subDay();

                $created['falls']             += $this->seedIncidents($participants, $reporter, 'fall', 4, 8, $periodStart, $periodEnd, 0.25);
                $created['hospitalizations']  += $this->seedIncidents($participants, $reporter, 'hospitalization', 1, 3, $periodStart, $periodEnd);
                $created['er_visits']         += $this->seedIncidents($participants, $reporter, 'er_visit', 2, 5, $periodStart, $periodEnd);
                $created['med_errors']        += $this->seedIncidents($participants, $reporter, 'medication_error', 0, 2, $periodStart, $periodEnd);
                $created['infections']        += $this->seedIncidents($participants, $reporter, 'infection', 0, 2, $periodStart, $periodEnd);
                $created['elopements']        += $this->seedIncidents($participants, $reporter, 'elopement', 0, 1, $periodStart, $periodEnd);
                $created['burns']             += $this->seedBurns($participants, $reporter, 0, 1, $periodStart, $periodEnd);
            }

            // Flu + pneumococcal seeds for the last flu season (~Sep-Nov).
            $created['immunizations'] += $this->seedFluShots($participants, $reporter);
        }

        $this->command?->info('Seeded Level I/II quality indicators:');
        foreach ($created as $k => $v) {
            $this->command?->info("   {$k}: {$v}");
        }
    }

    /** Start of the calendar quarter N quarters back (0 = current). */
    private function quarterStart(int $offset): Carbon
    {
        $ref = now()->subMonths($offset * 3);
        $q = (int) ceil($ref->month / 3);
        $startMonth = (($q - 1) * 3) + 1;
        return Carbon::createFromDate($ref->year, $startMonth, 1)->startOfDay();
    }

    private function seedIncidents(
        $participants,
        int $reporterId,
        string $type,
        int $min,
        int $max,
        Carbon $periodStart,
        Carbon $periodEnd,
        float $injuryRate = 0.0,
    ): int {
        $count = mt_rand($min, $max);
        $made = 0;
        for ($i = 0; $i < $count; $i++) {
            $p = $participants->random();
            $withInjury = mt_rand(1, 100) <= ($injuryRate * 100);

            Incident::create([
                'tenant_id'           => $p->tenant_id,
                'participant_id'      => $p->id,
                'incident_type'       => $type,
                'occurred_at'         => $this->randomDateBetween($periodStart, $periodEnd),
                'location_of_incident'=> $type === 'fall' ? 'Day Center common area' : 'Participant home',
                'reported_by_user_id' => $reporterId,
                'reported_at'         => $this->randomDateBetween($periodStart, $periodEnd),
                'description'         => $this->describe($type),
                'immediate_actions_taken' => 'Assessed, documented, IDT notified.',
                'injuries_sustained'  => $withInjury,
                'injury_description'  => $withInjury ? 'Minor contusion, no fracture.' : null,
                'rca_required'        => in_array($type, ['fall', 'medication_error', 'elopement', 'hospitalization', 'er_visit'], true),
                'rca_completed'       => false,
                'cms_reportable'      => in_array($type, ['abuse_neglect', 'unexpected_death'], true),
                'cms_notification_required' => false,
                'status'              => 'closed',
            ]);
            $made++;
        }
        return $made;
    }

    private function seedBurns($participants, int $reporterId, int $min, int $max, Carbon $start, Carbon $end): int
    {
        $count = mt_rand($min, $max);
        $made = 0;
        for ($i = 0; $i < $count; $i++) {
            $p = $participants->random();
            Incident::create([
                'tenant_id'           => $p->tenant_id,
                'participant_id'      => $p->id,
                'incident_type'       => 'injury',
                'occurred_at'         => $this->randomDateBetween($start, $end),
                'location_of_incident'=> 'Participant home',
                'reported_by_user_id' => $reporterId,
                'reported_at'         => $this->randomDateBetween($start, $end),
                'description'         => 'Participant sustained a minor thermal burn on forearm while cooking.',
                'immediate_actions_taken' => 'Cool compress applied, dressing changed daily.',
                'injuries_sustained'  => true,
                'injury_description'  => 'Superficial burn, <2cm.',
                'rca_required'        => false,
                'rca_completed'       => false,
                'cms_reportable'      => false,
                'cms_notification_required' => false,
                'status'              => 'closed',
            ]);
            $made++;
        }
        return $made;
    }

    private function seedFluShots($participants, int $reporterId): int
    {
        $fluStart = Carbon::create(now()->year - 1, 10, 1);
        $fluEnd   = Carbon::create(now()->year - 1, 12, 15);

        $made = 0;
        // ~70% flu coverage + ~45% pneumo coverage on enrolled participants.
        foreach ($participants as $p) {
            if (mt_rand(1, 100) <= 70) {
                Immunization::create([
                    'tenant_id'               => $p->tenant_id,
                    'participant_id'          => $p->id,
                    'vaccine_type'            => 'influenza',
                    'vaccine_name'            => 'Influenza Quadrivalent 2025-2026',
                    'cvx_code'                => '141',
                    'administered_date'       => $this->randomDateBetween($fluStart, $fluEnd)->toDateString(),
                    'administered_by_user_id' => $reporterId,
                    'lot_number'              => 'LOT-' . strtoupper(substr(md5((string) $p->id), 0, 6)),
                ]);
                $made++;
            }
            if (mt_rand(1, 100) <= 45) {
                Immunization::create([
                    'tenant_id'               => $p->tenant_id,
                    'participant_id'          => $p->id,
                    'vaccine_type'            => 'pneumococcal_ppsv23',
                    'vaccine_name'            => 'Pneumococcal PPSV23',
                    'cvx_code'                => '33',
                    'administered_date'       => $this->randomDateBetween($fluStart, $fluEnd)->toDateString(),
                    'administered_by_user_id' => $reporterId,
                    'lot_number'              => 'LOT-' . strtoupper(substr(md5((string) $p->id . 'p'), 0, 6)),
                ]);
                $made++;
            }
        }
        return $made;
    }

    private function randomDateBetween(Carbon $start, Carbon $end): Carbon
    {
        $range = max(1, $start->diffInSeconds($end));
        return $start->copy()->addSeconds(mt_rand(0, (int) $range));
    }

    private function describe(string $type): string
    {
        return match ($type) {
            'fall'              => 'Participant observed on floor, assisted up by staff, vitals stable.',
            'hospitalization'   => 'Admitted via ED for acute care; IDT notified; transition plan initiated.',
            'er_visit'          => 'Presented to ED; evaluated and released.',
            'medication_error'  => 'Missed scheduled dose — detected on next administration round.',
            'infection'         => 'Signs of UTI identified; treated empirically; cultures pending.',
            'elopement'         => 'Brief elopement from day center; located within 15 minutes.',
            default             => 'Event documented per PACE operational policy.',
        };
    }
}
