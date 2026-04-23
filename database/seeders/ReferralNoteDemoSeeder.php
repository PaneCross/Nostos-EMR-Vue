<?php

namespace Database\Seeders;

use App\Models\Referral;
use App\Models\ReferralNote;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds 2-3 demo notes on a handful of existing referrals so the feature
 * isn't empty on first load. Safe to re-run (skips already-noted referrals).
 */
class ReferralNoteDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->firstOrFail();

        $enrollmentUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'enrollment')
            ->first();

        if (! $enrollmentUser) {
            $this->command->info('  No enrollment staff user found — skipping referral notes seed.');
            return;
        }

        // Pick a few referrals across different pipeline stages to demo the thread.
        $targetReferrals = Referral::where('tenant_id', $tenant->id)
            ->whereIn('status', ['intake_scheduled', 'intake_in_progress', 'eligibility_pending', 'pending_enrollment'])
            ->limit(5)
            ->get();

        if ($targetReferrals->isEmpty()) {
            $this->command->info('  No in-progress referrals found — skipping referral notes seed.');
            return;
        }

        $threads = [
            [
                'Hospital social work reached out — discharge planning indicates high fall risk and limited family support.',
                'Voicemail left for prospective member to confirm intake appointment date. Awaiting callback.',
                'Family member called back. Intake scheduled for next Tuesday at 10am.',
            ],
            [
                'Initial phone screen complete. Strong candidate — meets age and residence requirements.',
                'Waiting on Medicaid eligibility confirmation from state portal. Typically 3-5 business days.',
            ],
            [
                'Pending NF-LOC (Nursing Facility Level of Care) determination. Provider scheduled for home assessment Friday.',
                'Assessment completed. Documentation submitted to state. No concerns flagged.',
                'State approval received. Moving forward to pending_enrollment.',
            ],
            [
                'Candidate expressed concerns about transportation coverage. Reviewed PACE benefits package with family.',
            ],
            [
                'All enrollment paperwork signed. Confirming first day-center day with family for next Monday.',
                'First day confirmed. Transport scheduled for 8:15am pickup.',
            ],
        ];

        $count = 0;
        foreach ($targetReferrals as $i => $referral) {
            // Skip if already has notes (idempotent)
            // Note: Referral has a `notes` TEXT column (initial free-form) AND
            // a `referralNotes()` HasMany — the thread relation is the latter.
            if ($referral->referralNotes()->count() > 0) continue;

            $thread = $threads[$i % count($threads)];
            foreach ($thread as $j => $content) {
                ReferralNote::create([
                    'tenant_id'   => $tenant->id,
                    'referral_id' => $referral->id,
                    'user_id'     => $enrollmentUser->id,
                    'content'     => $content,
                    // Stagger timestamps so thread looks realistic (oldest first)
                    'created_at'  => Carbon::now()->subDays(count($thread) - $j)->subMinutes(rand(0, 300)),
                ]);
                $count++;
            }
        }

        $this->command->info("  Seeded {$count} demo referral notes across " . $targetReferrals->count() . " referrals.");
    }
}
