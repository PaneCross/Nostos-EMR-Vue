<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase 15.10 — Medication formulary management ──────────────────────────
// Per-tenant PACE formulary (Part D capitated drug list + tiers).
// Coverage determinations track participant-specific exceptions.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_formulary_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->string('rxnorm_code', 20)->nullable();
            $t->string('drug_name', 200);
            $t->string('generic_name', 200)->nullable();
            $t->tinyInteger('tier')->default(1); // 1=preferred generic ... 5=specialty
            $t->boolean('prior_authorization_required')->default(false);
            $t->boolean('quantity_limit')->default(false);
            $t->string('quantity_limit_text', 200)->nullable();
            $t->boolean('step_therapy_required')->default(false);
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('added_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->timestamp('last_reviewed_at')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'drug_name'], 'formulary_tenant_drug_idx');
            $t->index(['tenant_id', 'rxnorm_code'], 'formulary_tenant_rxnorm_idx');
        });

        Schema::create('emr_coverage_determinations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->foreignId('formulary_entry_id')->nullable()->constrained('emr_formulary_entries')->nullOnDelete();
            $t->string('drug_name', 200);
            $t->string('rxnorm_code', 20)->nullable();
            $t->string('determination_type', 30); // prior_authorization|tier_exception|quantity_limit_override|step_therapy_override|formulary_exception
            $t->string('status', 20)->default('pending'); // pending|approved|denied|withdrawn
            $t->date('requested_at');
            $t->date('decided_at')->nullable();
            $t->text('clinical_justification')->nullable();
            $t->text('decision_reason')->nullable();
            $t->foreignId('requested_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->foreignId('decided_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->timestamps();

            $t->index(['tenant_id', 'status'], 'coverage_tenant_status_idx');
        });

        \DB::statement("
            ALTER TABLE emr_coverage_determinations
            ADD CONSTRAINT coverage_type_check
            CHECK (determination_type IN ('prior_authorization','tier_exception','quantity_limit_override','step_therapy_override','formulary_exception'))
        ");
        \DB::statement("
            ALTER TABLE emr_coverage_determinations
            ADD CONSTRAINT coverage_status_check
            CHECK (status IN ('pending','approved','denied','withdrawn'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_coverage_determinations');
        Schema::dropIfExists('emr_formulary_entries');
    }
};
