<?php

namespace Database\Seeders;

use App\Models\Participant;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Assigns a recurring day-center schedule to enrolled participants.
 * Real PACE programs typically have participants on 2-5 days/week patterns.
 * This seeder varies the patterns so the roster reflects realistic load.
 */
class DayCenterScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->first();
        if (! $tenant) {
            $this->command?->info('  No demo tenant found — skipping day-center schedule seeding.');
            return;
        }

        // Common day-center schedule patterns (weighted toward weekday attendance)
        $patterns = [
            ['mon', 'wed', 'fri'],           // MWF (most common)
            ['tue', 'thu'],                  // TTh
            ['mon', 'tue', 'wed', 'thu', 'fri'], // Every weekday (high-acuity)
            ['mon', 'wed', 'fri'],
            ['tue', 'thu'],
            ['mon', 'tue', 'thu'],           // Irregular
            ['wed', 'fri'],                  // Part-time
            ['mon', 'wed', 'fri'],
            ['tue', 'wed', 'thu'],           // Mid-week block
            ['mon', 'thu'],                  // Split
        ];

        $participants = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->where('is_active', true)
            ->get();

        if ($participants->isEmpty()) {
            $this->command->info('  No enrolled participants — skipping day-center schedule seeding.');
            return;
        }

        $count = 0;
        foreach ($participants as $i => $p) {
            $pattern = $patterns[$i % count($patterns)];
            $p->day_center_days = $pattern;
            $p->save();
            $count++;
        }

        $this->command->info("  Seeded day-center schedules for {$count} participants (MWF, TTh, daily, and mixed patterns).");
    }
}
