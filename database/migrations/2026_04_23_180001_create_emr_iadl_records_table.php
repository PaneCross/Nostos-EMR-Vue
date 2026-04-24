<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ─── Phase C1 — IADL assessment snapshots (Lawton scale) ────────────────────
// One row per complete IADL assessment. 8 binary items (0=unable, 1=independent).
// total_score = sum; interpretation is a derived band stored for quick queries.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_iadl_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->foreignId('recorded_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->timestamp('recorded_at');

            // Lawton IADL — 8 binary items (0=unable, 1=independent)
            $t->tinyInteger('telephone')->unsigned()->default(0);
            $t->tinyInteger('shopping')->unsigned()->default(0);
            $t->tinyInteger('food_preparation')->unsigned()->default(0);
            $t->tinyInteger('housekeeping')->unsigned()->default(0);
            $t->tinyInteger('laundry')->unsigned()->default(0);
            $t->tinyInteger('transportation')->unsigned()->default(0);
            $t->tinyInteger('medications')->unsigned()->default(0);
            $t->tinyInteger('finances')->unsigned()->default(0);

            $t->tinyInteger('total_score')->unsigned(); // 0-8
            $t->string('interpretation', 30); // independent|mild_impairment|moderate_impairment|severe_impairment
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'participant_id', 'recorded_at'], 'iadl_trend_idx');
        });

        // CHECK constraints: each item is 0 or 1, total_score 0-8, interpretation enum
        foreach (['telephone','shopping','food_preparation','housekeeping','laundry','transportation','medications','finances'] as $f) {
            DB::statement("ALTER TABLE emr_iadl_records ADD CONSTRAINT emr_iadl_{$f}_chk CHECK ({$f} IN (0,1))");
        }
        DB::statement("ALTER TABLE emr_iadl_records ADD CONSTRAINT emr_iadl_total_chk CHECK (total_score BETWEEN 0 AND 8)");
        DB::statement("ALTER TABLE emr_iadl_records ADD CONSTRAINT emr_iadl_interp_chk
            CHECK (interpretation IN ('independent','mild_impairment','moderate_impairment','severe_impairment'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_iadl_records');
    }
};
