<?php

// ─── CareGapDemoSeeder ───────────────────────────────────────────────────────
// Drives the real CareGapService against every enrolled participant in the
// demo tenant. The service evaluates 7 preventive measures (annual PCP visit,
// flu shot, pneumococcal, colonoscopy, mammogram, A1c, diabetic eye exam)
// against each participant's actual emr_clinical_notes / emr_immunizations /
// emr_problems history and writes one CareGap row per (participant, measure).
//
// Without this seeder /dashboards/gaps shows empty — the 02:00 nightly job
// never runs locally. After this runs, the dashboard's tenant summary chart
// + "My panel" table both populate. Pressing "Recompute now" on the page
// re-runs the same service, so signing a fresh PCP note in the demo and
// recomputing will close the annual_pcp_visit gap for that participant.
//
// When to run : after participant + clinical demo data exists. The
// DemoEnvironmentSeeder calls this near the bottom of its run() method.
// Idempotent : the service uses updateOrCreate on (tenant, participant,
// measure) so re-running just refreshes calculated_at + satisfied flags.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\CareGap;
use App\Models\Participant;
use App\Models\Tenant;
use App\Services\CareGapService;
use Illuminate\Database\Seeder;

class CareGapDemoSeeder extends Seeder
{
    public function run(CareGapService $svc): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->first() ?? Tenant::first();
        if (! $tenant) {
            $this->command?->warn('  No tenant — skipping care-gap evaluation.');
            return;
        }

        $participants = Participant::where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->where('enrollment_status', 'enrolled')
            ->get();

        if ($participants->isEmpty()) {
            $this->command?->warn('  No enrolled participants — skipping care-gap evaluation.');
            return;
        }

        $totalRows = 0;
        $openGaps  = 0;
        foreach ($participants as $p) {
            $results    = $svc->evaluate($p);
            $totalRows += count($results);
            $openGaps  += collect($results)->where('satisfied', false)->count();
        }

        $this->command?->line(sprintf(
            '    Care gaps: <comment>%d rows across %d participants · %d open gaps</comment>',
            $totalRows,
            $participants->count(),
            $openGaps,
        ));
    }
}
