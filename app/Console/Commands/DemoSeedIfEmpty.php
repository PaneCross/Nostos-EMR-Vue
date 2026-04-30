<?php

// ─── DemoSeedIfEmpty ─────────────────────────────────────────────────────────
// Idempotent seeder bootstrap for the Fly.io demo deployment.
//
// Runs from fly.toml's release_command on every deploy. If the DB has zero
// participants (fresh / wiped), it runs the full DemoEnvironmentSeeder and
// the deploy continues. If participants already exist, it logs "skipped"
// and exits cleanly so the deploy proceeds without re-seeding.
//
// Why this isn't an inline release_command :
//   1. The required logic (count + branch) is too messy to inline in fly.toml.
//   2. SSH-driven seeds were dying at the 3-minute Fly SSH timeout. Fly's
//      release_command machine has a 30-minute window, plenty for the full
//      seeder cluster.
//
// Usage : php artisan demo:seed-if-empty
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Console\Commands;

use App\Models\Participant;
use Database\Seeders\DemoEnvironmentSeeder;
use Illuminate\Console\Command;

class DemoSeedIfEmpty extends Command
{
    protected $signature = 'demo:seed-if-empty';

    protected $description = 'Seed the demo data set if the DB has zero participants ; no-op otherwise.';

    public function handle(): int
    {
        $count = Participant::count();
        if ($count > 0) {
            $this->info("Skipping demo seed : {$count} participants already exist.");
            return self::SUCCESS;
        }

        $this->info('DB is empty. Running DemoEnvironmentSeeder...');
        $this->call('db:seed', [
            '--class' => DemoEnvironmentSeeder::class,
            '--force' => true,
        ]);

        $this->info('Demo seed complete.');
        return self::SUCCESS;
    }
}
