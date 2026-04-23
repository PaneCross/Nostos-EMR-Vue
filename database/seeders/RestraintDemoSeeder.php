<?php

namespace Database\Seeders;

use App\Models\Participant;
use App\Models\RestraintEpisode;
use App\Models\RestraintMonitoringObservation;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Phase B1 — Restraint episodes demo data.
 *
 * Per tenant, seeds 3 episodes at different lifecycle stages so the tab + the
 * compliance universe pull have realistic variety:
 *
 *   1. Active chemical restraint initiated ~6 hours ago with 2 monitoring
 *      observations + NO IDT review yet (will later trigger IDT-overdue
 *      alert at the 24h mark via RestraintMonitoringOverdueJob).
 *   2. Discontinued physical restraint 3 days old with 6 observations and
 *      IDT review completed.
 *   3. Active physical restraint initiated 30 hours ago with ZERO monitoring
 *      observations — triggers BOTH monitoring-overdue and IDT-overdue
 *      alerts immediately on the next job run.
 *
 * Dedup: if any episode already exists on the seeded participants with the
 * "[demo]" marker in reason_text, skip.
 */
class RestraintDemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Tenant::all() as $tenant) {
            $this->seedForTenant($tenant);
        }
        $this->command?->info('    Restraint demo data seeded.');
    }

    private function seedForTenant(Tenant $tenant): void
    {
        $participants = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->inRandomOrder()->take(3)->get();
        if ($participants->count() < 3) return;

        $dedup = RestraintEpisode::forTenant($tenant->id)
            ->where('reason_text', 'like', '%[demo]%')->exists();
        if ($dedup) return;

        // No `nursing` dept in shared_users_department_check — nurses sit
        // under primary_care or home_care. Find a home_care or primary_care
        // user to play the nurse role; fall back to any tenant user.
        $nurse = User::where('tenant_id', $tenant->id)
            ->whereIn('department', ['home_care', 'primary_care'])
            ->first()
            ?? User::where('tenant_id', $tenant->id)->first();
        $provider = User::where('tenant_id', $tenant->id)
            ->where('department', 'primary_care')->first()
            ?? $nurse;
        $qa = User::where('tenant_id', $tenant->id)
            ->where('department', 'qa_compliance')->first()
            ?? $nurse;

        if (! $nurse || ! $provider) return;

        // 1. Active chemical — 6h ago, 2 observations, no IDT yet
        [$p1, $p2, $p3] = [$participants[0], $participants[1], $participants[2]];
        $ep1 = RestraintEpisode::create([
            'tenant_id'                 => $tenant->id,
            'participant_id'            => $p1->id,
            'restraint_type'            => 'chemical',
            'initiated_at'              => Carbon::now()->subHours(6),
            'initiated_by_user_id'      => $nurse->id,
            'reason_text'               => '[demo] Severe agitation with threats of self-harm after fall. Behavioral de-escalation attempts unsuccessful.',
            'alternatives_tried_text'   => '[demo] 1-on-1 sitter x 45 min, reduced stimulation, family called. All ineffective.',
            'ordering_provider_user_id' => $provider->id,
            'medication_text'           => 'haloperidol 2 mg IM x1',
            'monitoring_interval_min'   => 30,
            'status'                    => 'active',
        ]);
        RestraintMonitoringObservation::create([
            'tenant_id' => $tenant->id, 'restraint_episode_id' => $ep1->id,
            'observed_by_user_id' => $nurse->id,
            'observed_at' => Carbon::now()->subHours(5),
            'skin_integrity' => 'intact', 'circulation' => 'adequate',
            'mental_status' => 'sedated',
            'toileting_offered' => true, 'hydration_offered' => true, 'repositioning_done' => true,
            'notes' => '[demo] Participant calm, VSS.',
        ]);
        RestraintMonitoringObservation::create([
            'tenant_id' => $tenant->id, 'restraint_episode_id' => $ep1->id,
            'observed_by_user_id' => $nurse->id,
            'observed_at' => Carbon::now()->subHours(3),
            'skin_integrity' => 'intact', 'circulation' => 'adequate',
            'mental_status' => 'calm',
            'toileting_offered' => false, 'hydration_offered' => true, 'repositioning_done' => false,
            'notes' => '[demo] Alert, oriented x2.',
        ]);

        // 2. Discontinued physical — 3 days old, full IDT review
        $startedAt = Carbon::now()->subDays(3);
        $ep2 = RestraintEpisode::create([
            'tenant_id'                => $tenant->id,
            'participant_id'           => $p2->id,
            'restraint_type'           => 'physical',
            'initiated_at'             => $startedAt,
            'initiated_by_user_id'     => $nurse->id,
            'reason_text'              => '[demo] Participant attempting to remove IV + oxygen. Non-compliant with verbal redirection.',
            'alternatives_tried_text'  => '[demo] Repositioned IV, explained need, offered hand for participant to hold. All failed.',
            'monitoring_interval_min'  => 15,
            'status'                   => 'discontinued',
            'discontinued_at'          => $startedAt->copy()->addHours(4),
            'discontinued_by_user_id'  => $nurse->id,
            'discontinuation_reason'   => '[demo] Participant calm, IV removed per plan, back to baseline.',
            'idt_review_date'          => $startedAt->copy()->addDay()->toDateString(),
            'idt_review_user_id'       => $qa->id,
            'outcome_text'             => '[demo] IDT reviewed + concurred. No pattern; continue standard fall-prevention. No restraint-reduction plan needed at this time.',
        ]);
        for ($m = 0; $m < 6; $m++) {
            RestraintMonitoringObservation::create([
                'tenant_id' => $tenant->id, 'restraint_episode_id' => $ep2->id,
                'observed_by_user_id' => $nurse->id,
                'observed_at' => $startedAt->copy()->addMinutes(20 * ($m + 1)),
                'skin_integrity' => 'intact', 'circulation' => 'adequate',
                'mental_status' => $m < 2 ? 'agitated' : 'calm',
                'toileting_offered' => $m % 2 === 0,
                'hydration_offered' => true,
                'repositioning_done' => $m % 2 === 1,
                'notes' => '[demo] Routine check.',
            ]);
        }

        // 3. Active physical — 30h old, no observations (triggers both alerts)
        RestraintEpisode::create([
            'tenant_id'                => $tenant->id,
            'participant_id'           => $p3->id,
            'restraint_type'           => 'physical',
            'initiated_at'             => Carbon::now()->subHours(30),
            'initiated_by_user_id'     => $nurse->id,
            'reason_text'              => '[demo] Hand mitts applied for participant safety after repeated self-scratching of facial incision.',
            'alternatives_tried_text'  => '[demo] Long sleeves, nail trim, caregiver redirection. Failed.',
            'monitoring_interval_min'  => 15,
            'status'                   => 'active',
        ]);
    }
}
