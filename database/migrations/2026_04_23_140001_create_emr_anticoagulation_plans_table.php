<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ─── Phase B5 — Anticoagulation plans ───────────────────────────────────────
// One plan per participant × agent × active-window. target_inr_low/high only
// meaningful for warfarin (stored as NULL for DOACs). Status inferred from
// stop_date: null → active, else → stopped.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_anticoagulation_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->string('agent', 30);  // warfarin|apixaban|rivaroxaban|dabigatran|edoxaban|enoxaparin|other
            $t->decimal('target_inr_low', 3, 1)->nullable();   // warfarin only
            $t->decimal('target_inr_high', 3, 1)->nullable();
            $t->integer('monitoring_interval_days')->nullable(); // typical: 30 for stable warfarin
            $t->date('start_date');
            $t->date('stop_date')->nullable();
            $t->string('stop_reason', 200)->nullable();
            $t->foreignId('prescribing_provider_user_id')->nullable()
                ->constrained('shared_users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'participant_id', 'stop_date'], 'antico_plans_active_idx');
        });

        DB::statement("
            ALTER TABLE emr_anticoagulation_plans
            ADD CONSTRAINT emr_antico_plans_agent_check
            CHECK (agent IN ('warfarin','apixaban','rivaroxaban','dabigatran','edoxaban','enoxaparin','other'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_anticoagulation_plans');
    }
};
