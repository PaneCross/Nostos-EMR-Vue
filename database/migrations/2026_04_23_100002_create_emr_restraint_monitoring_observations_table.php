<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ─── Phase B1 — Restraint monitoring observations ────────────────────────────
// One row per observation / check on an active restraint episode.
// Nursing records at the episode's declared monitoring_interval_min.
// If no observation has been recorded for > 4h on an active episode, an alert
// fires (see RestraintMonitoringOverdueJob).
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_restraint_monitoring_observations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('restraint_episode_id')->constrained('emr_restraint_episodes')->cascadeOnDelete();
            $t->foreignId('observed_by_user_id')->constrained('shared_users')->restrictOnDelete();
            $t->timestamp('observed_at');

            // Clinical observation fields
            $t->string('skin_integrity', 20)->nullable();  // intact|reddened|broken|other
            $t->string('circulation', 20)->nullable();     // adequate|diminished|absent
            $t->string('mental_status', 30)->nullable();   // calm|agitated|sedated|unresponsive|other
            $t->boolean('toileting_offered')->default(false);
            $t->boolean('hydration_offered')->default(false);
            $t->boolean('repositioning_done')->default(false);

            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index(['restraint_episode_id', 'observed_at'],
                'restraint_obs_episode_obs_idx');
        });

        DB::statement("
            ALTER TABLE emr_restraint_monitoring_observations
            ADD CONSTRAINT emr_restraint_obs_skin_check
            CHECK (skin_integrity IS NULL OR skin_integrity IN ('intact','reddened','broken','other'))
        ");
        DB::statement("
            ALTER TABLE emr_restraint_monitoring_observations
            ADD CONSTRAINT emr_restraint_obs_circulation_check
            CHECK (circulation IS NULL OR circulation IN ('adequate','diminished','absent'))
        ");
        DB::statement("
            ALTER TABLE emr_restraint_monitoring_observations
            ADD CONSTRAINT emr_restraint_obs_mental_check
            CHECK (mental_status IS NULL OR mental_status IN ('calm','agitated','sedated','unresponsive','other'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_restraint_monitoring_observations');
    }
};
