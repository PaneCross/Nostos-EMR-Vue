<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ─── Phase B6 — Critical value acknowledgments ──────────────────────────────
// One row per flagged out-of-range vital (or later, lab). Provider must
// acknowledge + document action within deadline_at. Unacknowledged critical
// entries escalate via CriticalValueEscalationJob.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_critical_value_acknowledgments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->foreignId('vital_id')->nullable()->constrained('emr_vitals')->nullOnDelete();
            $t->foreignId('lab_result_id')->nullable()->constrained('emr_lab_results')->nullOnDelete();
            $t->string('field_name', 40);
            $t->decimal('value', 10, 2);
            $t->string('severity', 20); // warning|critical
            $t->string('direction', 10); // low|high
            $t->timestamp('deadline_at');
            $t->timestamp('acknowledged_at')->nullable();
            $t->foreignId('acknowledged_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->text('action_taken_text')->nullable();
            $t->timestamp('escalated_at')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'acknowledged_at', 'deadline_at'], 'crit_val_ack_pending_idx');
        });

        DB::statement("
            ALTER TABLE emr_critical_value_acknowledgments
            ADD CONSTRAINT crit_val_ack_severity_check
            CHECK (severity IN ('warning','critical'))
        ");
        DB::statement("
            ALTER TABLE emr_critical_value_acknowledgments
            ADD CONSTRAINT crit_val_ack_direction_check
            CHECK (direction IN ('low','high'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_critical_value_acknowledgments');
    }
};
