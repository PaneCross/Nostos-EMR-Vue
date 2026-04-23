<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase B5 — INR results ─────────────────────────────────────────────────
// Append-only per-draw INR record. Links to an anticoagulation plan when
// drawn under one. in_range flag pre-computed at record time.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_inr_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->foreignId('anticoagulation_plan_id')->nullable()
                ->constrained('emr_anticoagulation_plans')->nullOnDelete();
            $t->timestamp('drawn_at');
            $t->decimal('value', 3, 1);
            $t->boolean('in_range')->nullable(); // null if no plan
            $t->text('dose_adjustment_text')->nullable();
            $t->foreignId('recorded_by_user_id')->nullable()
                ->constrained('shared_users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'participant_id', 'drawn_at'], 'inr_trend_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_inr_results');
    }
};
