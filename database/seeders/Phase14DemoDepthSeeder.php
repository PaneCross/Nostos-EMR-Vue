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
        if (! class_exists(StaffCredential::class)) return;

        // Only proceed if the credentials table actually exists + the seeder
        // can find clinical staff users.
        try {
            $staff = User::where('tenant_id', $tenant->id)
                ->whereIn('department', ['primary_care', 'nursing', 'therapies', 'pharmacy'])
                ->inRandomOrder()->take(3)->get();
        } catch (\Throwable) { return; }

        if ($staff->isEmpty()) return;

        $exists = StaffCredential::where('tenant_id', $tenant->id)
            ->where('notes', 'like', '%[demo-depth]%')->exists();
        if ($exists) return;

        $plans = [
            ['RN License',     7],
            ['BLS Certification', 20],
            ['DEA Registration',  28],
        ];

        foreach ($plans as $i => [$type, $daysUntilExpiry]) {
            $user = $staff[$i] ?? $staff->first();
            StaffCredential::create([
                'tenant_id'   => $tenant->id,
                'user_id'     => $user->id,
                'credential_type' => $type,
                'issuing_body'    => 'State board (demo)',
                'license_number'  => 'DEMO-' . strtoupper(bin2hex(random_bytes(3))),
                'issue_date'      => Carbon::now()->subYears(2)->toDateString(),
                'expires_at'      => Carbon::now()->addDays($daysUntilExpiry)->toDateString(),
                'is_active'       => true,
                'notes'           => '[demo-depth] Expiring soon — demo data.',
            ]);
        }
    }

    private function seedPendingAppeals(Tenant $tenant): void
    {
        if (! class_exists(Appeal::class)) return;
        $exists = Appeal::where('tenant_id', $tenant->id)
            ->where('appeal_reason', 'like', '%[demo-depth]%')->exists();
        if ($exists) return;

        $participants = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->inRandomOrder()->take(2)->get();
        if ($participants->isEmpty()) return;

        $filer = User::where('tenant_id', $tenant->id)
            ->where('department', 'qa_compliance')->first()
            ?? User::where('tenant_id', $tenant->id)->first();
        if (! $filer) return;

        // Best effort — skip silently if Appeal model signature differs
        // from what we expect. Demo data, not mission critical.
        try {
            foreach ($participants as $i => $p) {
                Appeal::create([
                    'tenant_id'         => $tenant->id,
                    'participant_id'    => $p->id,
                    'appeal_level'      => $i === 0 ? 'standard' : 'expedited',
                    'status'            => $i === 0 ? 'filed' : 'under_review',
                    'filed_date'        => Carbon::now()->subDays($i === 0 ? 7 : 1)->toDateString(),
                    'decision_due_date' => Carbon::now()->addDays($i === 0 ? 23 : 2)->toDateString(),
                    'appeal_reason'     => '[demo-depth] Participant challenges service denial for ' . ($i === 0 ? 'physical therapy expansion.' : 'expedited wheelchair replacement.'),
                    'filed_by_user_id'  => $filer->id,
                ]);
            }
        } catch (\Throwable $e) {
            $this->command?->warn('    Skipped appeals seed (model shape mismatch): ' . substr($e->getMessage(), 0, 80));
        }
    }
}
