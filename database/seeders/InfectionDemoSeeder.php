<?php

namespace Database\Seeders;

use App\Models\InfectionCase;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OutbreakDetectionService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Phase B2 — Infection surveillance demo data.
 *
 * Per tenant, seeds:
 *   - 1 isolated community influenza case (mild, resolved) — no outbreak.
 *   - 4 recent norovirus cases at the same site within 5 days — triggers
 *     automatic outbreak declaration via OutbreakDetectionService (≥3 in 7d).
 *   - 1 unresolved severe COVID-19 case requiring hospitalization.
 *
 * Dedup: if any case already has "[demo]" in notes on this tenant, skip.
 */
class InfectionDemoSeeder extends Seeder
{
    public function run(): void
    {
        $detector = app(OutbreakDetectionService::class);
        foreach (Tenant::all() as $tenant) {
            $this->seedForTenant($tenant, $detector);
        }
        $this->command?->info('    Infection surveillance demo data seeded.');
    }

    private function seedForTenant(Tenant $tenant, OutbreakDetectionService $detector): void
    {
        if (InfectionCase::forTenant($tenant->id)->where('notes', 'like', '%[demo]%')->exists()) {
            return;
        }

        $participants = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->whereNotNull('site_id')
            ->inRandomOrder()->take(6)->get();
        if ($participants->count() < 6) return;

        $reporter = User::where('tenant_id', $tenant->id)
            ->whereIn('department', ['primary_care', 'home_care', 'qa_compliance'])
            ->first()
            ?? User::where('tenant_id', $tenant->id)->first();
        if (! $reporter) return;

        // 1. Isolated community flu case (mild, resolved).
        InfectionCase::create([
            'tenant_id'                => $tenant->id,
            'participant_id'           => $participants[0]->id,
            'site_id'                  => $participants[0]->site_id,
            'organism_type'            => 'influenza',
            'organism_detail'          => 'Influenza A (H3N2)',
            'onset_date'               => Carbon::now()->subDays(14),
            'resolution_date'          => Carbon::now()->subDays(6),
            'severity'                 => 'mild',
            'source'                   => 'community',
            'hospitalization_required' => false,
            'reported_by_user_id'      => $reporter->id,
            'notes'                    => '[demo] Isolated community exposure. Resolved with supportive care.',
        ]);

        // 2. Norovirus cluster — 4 cases at same site over 5 days → outbreak.
        // Pick the site of the first "cluster" participant and fetch 4 enrolled
        // participants at that site.
        $clusterSiteId = $participants[1]->site_id;
        $clusterParticipants = Participant::where('tenant_id', $tenant->id)
            ->where('site_id', $clusterSiteId)
            ->where('enrollment_status', 'enrolled')
            ->inRandomOrder()->take(4)->get();

        if ($clusterParticipants->count() >= 4) {
            foreach ($clusterParticipants as $i => $p) {
                $case = InfectionCase::create([
                    'tenant_id'                => $tenant->id,
                    'participant_id'           => $p->id,
                    'site_id'                  => $clusterSiteId,
                    'organism_type'            => 'norovirus',
                    'onset_date'               => Carbon::now()->subDays(5 - $i),
                    'severity'                 => $i === 0 ? 'moderate' : 'mild',
                    'source'                   => 'facility',
                    'hospitalization_required' => false,
                    'reported_by_user_id'      => $reporter->id,
                    'notes'                    => '[demo] Suspected facility outbreak cluster.',
                ]);
                // Trigger detection on final case
                if ($i === $clusterParticipants->count() - 1) {
                    $detector->evaluateCase($case);
                }
            }
        }

        // 3. Severe unresolved COVID case requiring hospitalization.
        InfectionCase::create([
            'tenant_id'                => $tenant->id,
            'participant_id'           => $participants[5]->id,
            'site_id'                  => $participants[5]->site_id,
            'organism_type'            => 'covid19',
            'organism_detail'          => 'SARS-CoV-2 variant XEC',
            'onset_date'               => Carbon::now()->subDays(2),
            'severity'                 => 'severe',
            'source'                   => 'unknown',
            'hospitalization_required' => true,
            'isolation_started_at'     => Carbon::now()->subDays(2),
            'reported_by_user_id'      => $reporter->id,
            'notes'                    => '[demo] Hospitalized; pending discharge. Isolation precautions active.',
        ]);
    }
}
