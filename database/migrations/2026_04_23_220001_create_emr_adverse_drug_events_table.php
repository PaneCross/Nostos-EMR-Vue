<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_adverse_drug_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->foreignId('medication_id')->nullable()->constrained('emr_medications')->nullOnDelete();
            $t->date('onset_date');
            $t->string('severity', 20);      // mild|moderate|severe|life_threatening|fatal
            $t->text('reaction_description');
            $t->string('causality', 20);     // definite|probable|possible|unlikely
            $t->foreignId('reporter_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->timestamp('reported_to_medwatch_at')->nullable();
            $t->string('medwatch_tracking_number', 50)->nullable();
            $t->text('outcome_text')->nullable();
            $t->boolean('auto_allergy_created')->default(false);
            $t->timestamps();

            $t->index(['tenant_id', 'onset_date'], 'ade_tenant_onset_idx');
            $t->index(['tenant_id', 'severity'], 'ade_severity_idx');
        });

        DB::statement("ALTER TABLE emr_adverse_drug_events ADD CONSTRAINT ade_severity_chk
            CHECK (severity IN ('mild','moderate','severe','life_threatening','fatal'))");
        DB::statement("ALTER TABLE emr_adverse_drug_events ADD CONSTRAINT ade_causality_chk
            CHECK (causality IN ('definite','probable','possible','unlikely'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_adverse_drug_events');
    }
};
