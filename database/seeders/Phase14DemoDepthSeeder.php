<?php

namespace Database\Seeders;

use App\Models\Appeal;
use App\Models\Grievance;
use App\Models\Participant;
use App\Models\Site;
use App\Models\StaffCredential;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 14.8 — Seed-data depth additions.
 *
 * Existing seeders cover baseline data. Adds the specific states the demo
 * needs to show off late-phase features:
 *
 *   - Open grievances at multiple aging-band stages (green / yellow / red / overdue)
 *   - A handful of staff credentials expiring within 30 days
 *   - Pending appeals at different state-machine stages (Phase 1 feature)
 *
 * Safe to re-run — inserts only if none of the type exist yet.
 */
class Phase14DemoDepthSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Tenant::all() as $tenant) {
            $this->seedGrievances($tenant);
            $this->seedExpiringCredentials($tenant);
            $this->seedPendingAppeals($tenant);
        }

        $this->command?->info('    Phase 14.8 depth seed complete.');
    }

    private function seedGrievances(Tenant $tenant): void
    {
        $participants = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->inRandomOrder()->take(4)->get();
        if ($participants->count() < 4) return;

        $qaUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'qa_compliance')->first()
            ?? User::where('tenant_id', $tenant->id)->first();
        if (! $qaUser) return;

        $site = Site::where('tenant_id', $tenant->id)->first();
        if (! $site) return;

        // Dedup check — one run is enough
        if (Grievance::forTenant($tenant->id)
            ->where('description', 'like', '%[demo-depth]%')->exists()) {
            return;
        }

        $plans = [
            [10, 'green',   'Participant complained transport arrived 15 min late.'],
            [20, 'yellow',  'Family member requested interpreter at clinic visit; none available.'],
            [28, 'red',     'Participant reports difficulty reaching primary care provider on weekends.'],
            [33, 'overdue', 'Dietary complaint — meal preferences not followed at day center.'],
        ];

        foreach ($plans as $i => [$daysAgo, $_band, $summary]) {
            $p = $participants[$i];
            Grievance::create([
                'tenant_id' => $tenant->id,
                'site_id'   => $site->id,
                'participant_id' => $p->id,
                'category' => 'quality_of_care',
                'priority' => 'standard',
                'status'   => 'open',
                'filed_at' => Carbon::now()->subDays($daysAgo),
                'description' => "[demo-depth] {$summary}",
                'filed_by_type' => 'participant',
                'filed_by_name' => $p->first_name . ' ' . $p->last_name,
                'received_by_user_id' => $qaUser->id,
            ]);
        }
    }

    private function seedExpiringCredentials(Tenant $tenant): void
    {
        $staff = User::where('tenant_id', $tenant->id)
            ->whereIn('department', ['primary_care', 'nursing', 'therapies', 'pharmacy'])
            ->inRandomOrder()->take(3)->get();

        if ($staff->isEmpty()) return;

        $exists = StaffCredential::where('tenant_id', $tenant->id)
            ->where('notes', 'like', '%[demo-depth]%')->exists();
        if ($exists) return;

        // Real schema: credential_type is an enum (license|certification|...);
        // display name goes in `title`; dates are `issued_at` / `expires_at`;
        // no `issuing_body` / `is_active` columns.
        $plans = [
            // [credential_type, title, daysUntilExpiry]
            ['license',       'RN License',         7],
            ['certification', 'BLS Certification', 20],
            ['license',       'DEA Registration',  28],
        ];

        foreach ($plans as $i => [$credType, $title, $daysUntilExpiry]) {
            $user = $staff[$i] ?? $staff->first();
            StaffCredential::create([
                'tenant_id'       => $tenant->id,
                'user_id'         => $user->id,
                'credential_type' => $credType,
                'title'           => $title,
                'license_number'  => 'DEMO-' . strtoupper(bin2hex(random_bytes(3))),
                'issued_at'       => Carbon::now()->subYears(2)->toDateString(),
                'expires_at'      => Carbon::now()->addDays($daysUntilExpiry)->toDateString(),
                'notes'           => '[demo-depth] Expiring soon — demo data.',
            ]);
        }
    }

    private function seedPendingAppeals(Tenant $tenant): void
    {
        // Appeals REQUIRE an emr_service_denial_notices row (non-nullable FK).
        // If none exist for this tenant, nothing to appeal against — log + skip.
        // The earlier silent try/catch was masking this prerequisite rather
        // than handling it correctly. Phase A1 tech-debt: fail loudly, or
        // skip loudly, but never silently.
        $dedupExists = Appeal::where('tenant_id', $tenant->id)
            ->where('filing_reason', 'like', '%[demo-depth]%')->exists();
        if ($dedupExists) return;

        $denialNotices = \App\Models\ServiceDenialNotice::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')->take(2)->get();

        if ($denialNotices->isEmpty()) {
            $this->command?->line("    Tenant {$tenant->id}: no service denial notices exist yet — skipping appeals seed (ordering: denial notices must be seeded first).");
            return;
        }

        $filer = User::where('tenant_id', $tenant->id)
            ->where('department', 'qa_compliance')->first()
            ?? User::where('tenant_id', $tenant->id)->first();
        if (! $filer) return;

        foreach ($denialNotices as $i => $notice) {
            $type = $i === 0 ? Appeal::TYPE_STANDARD : Appeal::TYPE_EXPEDITED;
            $filedAt = $i === 0 ? Carbon::now()->subDays(7) : Carbon::now()->subDays(1);
            $dueAt   = $i === 0
                ? $filedAt->copy()->addDays(Appeal::STANDARD_DECISION_WINDOW_DAYS)
                : $filedAt->copy()->addHours(Appeal::EXPEDITED_DECISION_WINDOW_HOURS);

            Appeal::create([
                'tenant_id'                 => $tenant->id,
                'participant_id'            => $notice->participant_id,
                'service_denial_notice_id'  => $notice->id,
                'type'                      => $type,
                'status'                    => $i === 0 ? Appeal::STATUS_RECEIVED : Appeal::STATUS_UNDER_REVIEW,
                'filed_by'                  => 'participant',
                'filed_by_name'             => 'Self (demo)',
                'filing_reason'             => '[demo-depth] Participant challenges service denial for '
                    . ($i === 0 ? 'physical therapy expansion.' : 'expedited wheelchair replacement.'),
                'filed_at'                  => $filedAt,
                'internal_decision_due_at'  => $dueAt,
            ]);
        }
    }
}
