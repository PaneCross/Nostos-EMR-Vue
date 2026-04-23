<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ─── Phase B2 — Individual infection cases ──────────────────────────────────
// Each case is one participant with one organism. Links to an outbreak when
// part of a cluster. Drives /compliance/infection-surveillance audit universe.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_infection_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->foreignId('site_id')->nullable()->constrained('shared_sites')->nullOnDelete();
            $t->foreignId('outbreak_id')->nullable()->constrained('emr_infection_outbreaks')->nullOnDelete();
            $t->string('organism_type', 40);
            $t->string('organism_detail')->nullable();
            $t->date('onset_date');
            $t->date('resolution_date')->nullable();
            $t->string('severity', 20)->default('mild'); // mild|moderate|severe|fatal
            $t->boolean('hospitalization_required')->default(false);
            $t->timestamp('isolation_started_at')->nullable();
            $t->timestamp('isolation_ended_at')->nullable();
            $t->string('source', 20)->default('unknown'); // community|facility|healthcare|unknown
            $t->timestamp('reported_to_state_at')->nullable();
            $t->foreignId('reported_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'organism_type', 'onset_date'], 'infection_cases_detect_idx');
            $t->index(['tenant_id', 'participant_id'], 'infection_cases_tenant_participant_idx');
            $t->index('outbreak_id', 'infection_cases_outbreak_idx');
        });

        DB::statement("
            ALTER TABLE emr_infection_cases
            ADD CONSTRAINT emr_infection_cases_severity_check
            CHECK (severity IN ('mild','moderate','severe','fatal'))
        ");
        DB::statement("
            ALTER TABLE emr_infection_cases
            ADD CONSTRAINT emr_infection_cases_source_check
            CHECK (source IN ('community','facility','healthcare','unknown'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_infection_cases');
    }
};
