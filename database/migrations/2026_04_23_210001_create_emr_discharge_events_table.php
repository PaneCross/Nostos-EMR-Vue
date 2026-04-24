<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase C4 — Structured discharge checklist ──────────────────────────────
return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_discharge_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->string('discharge_from_facility', 200);
            $t->date('discharged_on');
            $t->decimal('readmission_risk_score', 5, 2)->nullable(); // LACE+
            $t->jsonb('checklist')->nullable(); // array of item objects
            $t->boolean('auto_created_from_adt')->default(false);
            $t->foreignId('created_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'discharged_on'], 'discharge_events_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_discharge_events');
    }
};
