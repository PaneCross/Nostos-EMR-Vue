<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ─── Phase B1 — Restraints documentation ─────────────────────────────────────
// 42 CFR §460 + CMS PACE Audit Protocol require documented physical + chemical
// restraint episodes with provider orders (for chemical), monitoring
// observations at a declared interval, and IDT review within 24 hours.
//
// Critical for surveyor compliance. Previously missing entirely — a CMS
// surveyor walk-in asking "show me last 12 months of restraint events" got
// nothing from this EMR.
//
// One episode per start/stop; multiple observations per episode.
// Append-only audit trail via shared_audit_logs.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_restraint_episodes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();

            $t->string('restraint_type', 20); // physical|chemical|both
            $t->timestamp('initiated_at');
            $t->foreignId('initiated_by_user_id')->constrained('shared_users')->restrictOnDelete();

            // Clinical rationale — required at initiation
            $t->text('reason_text');
            $t->text('alternatives_tried_text')->nullable();

            // For chemical restraints — provider order is required
            $t->foreignId('ordering_provider_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->string('medication_text')->nullable(); // free-text med name + dose (if chemical)

            // Monitoring interval in minutes (default 15 for physical, 30 for chemical)
            $t->integer('monitoring_interval_min')->default(15);

            // Lifecycle
            $t->string('status', 20)->default('active'); // active|discontinued|expired
            $t->timestamp('discontinued_at')->nullable();
            $t->foreignId('discontinued_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->text('discontinuation_reason')->nullable();

            // IDT review (24h deadline)
            $t->date('idt_review_date')->nullable();
            $t->foreignId('idt_review_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->text('outcome_text')->nullable();

            $t->timestamps();

            $t->index(['tenant_id', 'status'], 'restraint_episodes_tenant_status_idx');
            $t->index(['tenant_id', 'participant_id'], 'restraint_episodes_participant_idx');
            $t->index('initiated_at', 'restraint_episodes_initiated_idx');
        });

        DB::statement("
            ALTER TABLE emr_restraint_episodes
            ADD CONSTRAINT emr_restraint_episodes_type_check
            CHECK (restraint_type IN ('physical','chemical','both'))
        ");
        DB::statement("
            ALTER TABLE emr_restraint_episodes
            ADD CONSTRAINT emr_restraint_episodes_status_check
            CHECK (status IN ('active','discontinued','expired'))
        ");
        // Chemical restraints must have an ordering provider.
        DB::statement("
            ALTER TABLE emr_restraint_episodes
            ADD CONSTRAINT emr_restraint_episodes_chemical_requires_provider_check
            CHECK (
                (restraint_type = 'physical') OR
                (restraint_type IN ('chemical','both') AND ordering_provider_user_id IS NOT NULL)
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_restraint_episodes');
    }
};
