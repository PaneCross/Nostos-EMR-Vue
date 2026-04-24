<?php

// ─── Phase M4 — predictive model versioning ─────────────────────────────────
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_predictive_model_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('risk_type', 40); // disenrollment | acute_event
            $t->unsignedInteger('version_number');
            $t->string('algorithm', 40)->default('logistic_regression');
            $t->json('coefficients'); // feature -> weight
            $t->decimal('training_accuracy', 5, 4)->nullable();
            $t->unsignedInteger('training_sample_size')->default(0);
            $t->timestamp('trained_at')->useCurrent();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['tenant_id', 'risk_type']);
            $t->unique(['tenant_id', 'risk_type', 'version_number']);
        });
    }
    public function down(): void { Schema::dropIfExists('emr_predictive_model_versions'); }
};
