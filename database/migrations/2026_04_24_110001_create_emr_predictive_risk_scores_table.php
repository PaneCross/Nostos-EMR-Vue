<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_predictive_risk_scores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->string('model_version', 20);      // e.g. "g8-v1-demo"
            $t->string('risk_type', 30);          // disenrollment | acute_event
            $t->integer('score');                 // 0-100
            $t->string('band', 20);               // low | medium | high
            $t->jsonb('factors');                 // {feature: value, weight: contribution}
            $t->timestamp('computed_at');
            $t->timestamps();

            $t->index(['tenant_id', 'risk_type', 'computed_at'], 'pred_risk_trend_idx');
            $t->index(['tenant_id', 'risk_type', 'score'], 'pred_risk_score_idx');
        });

        DB::statement("ALTER TABLE emr_predictive_risk_scores ADD CONSTRAINT pred_risk_type_chk
            CHECK (risk_type IN ('disenrollment','acute_event'))");
        DB::statement("ALTER TABLE emr_predictive_risk_scores ADD CONSTRAINT pred_risk_band_chk
            CHECK (band IN ('low','medium','high'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_predictive_risk_scores');
    }
};
