<?php

// ─── Phase O6 — FK from risk scores to the trained model version ───────────
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('emr_predictive_risk_scores', function (Blueprint $t) {
            $t->unsignedBigInteger('model_version_id')->nullable()->after('model_version');
            $t->foreign('model_version_id')->references('id')->on('emr_predictive_model_versions')->nullOnDelete();
            $t->index('model_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('emr_predictive_risk_scores', function (Blueprint $t) {
            $t->dropForeign(['model_version_id']);
            $t->dropIndex(['model_version_id']);
            $t->dropColumn('model_version_id');
        });
    }
};
