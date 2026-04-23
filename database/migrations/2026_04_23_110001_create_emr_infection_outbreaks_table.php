<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ─── Phase B2 — Infection outbreaks ──────────────────────────────────────────
// 42 CFR §460 infection-control. One outbreak per site + organism + start
// window. Auto-created by OutbreakDetectionService when ≥3 cases of the same
// organism occur at the same site within 7 days.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_infection_outbreaks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('site_id')->nullable()->constrained('shared_sites')->nullOnDelete();
            $t->string('organism_type', 40);
            $t->string('organism_detail')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('declared_ended_at')->nullable();
            $t->decimal('attack_rate_pct', 5, 2)->nullable(); // 0-100
            $t->text('containment_measures_text')->nullable();
            $t->timestamp('reported_to_state_at')->nullable();
            $t->string('status', 20)->default('active'); // active|contained|ended
            $t->foreignId('declared_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'site_id', 'organism_type', 'status'], 'infection_outbreaks_lookup_idx');
            $t->index(['tenant_id', 'started_at'], 'infection_outbreaks_tenant_started_idx');
        });

        DB::statement("
            ALTER TABLE emr_infection_outbreaks
            ADD CONSTRAINT emr_infection_outbreaks_status_check
            CHECK (status IN ('active','contained','ended'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_infection_outbreaks');
    }
};
