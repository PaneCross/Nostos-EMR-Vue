<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase 8 (MVP roadmap) — Immunization submission log ─────────────────────
// Append-only record of HL7 VXU messages generated for an immunization.
// Stores the rendered VXU payload + status for audit. Actual transmission is
// not wired — "submitted" is a tracking flag (honest labeling).
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_immunization_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->constrained('emr_participants')->cascadeOnDelete();
            $t->foreignId('immunization_id')->constrained('emr_immunizations')->cascadeOnDelete();
            $t->string('state_code', 2);
            $t->string('message_control_id', 60);
            $t->longText('vxu_message');
            $t->string('status', 30)->default('generated'); // generated|submitted|acknowledged|rejected
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->text('ack_message')->nullable();
            $t->foreignId('generated_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->timestamps();

            $t->index(['tenant_id', 'participant_id'], 'emr_imm_sub_participant_idx');
            $t->unique('message_control_id', 'emr_imm_sub_mcid_uq');
        });

        // CHECK constraint enforcing known statuses
        \DB::statement("
            ALTER TABLE emr_immunization_submissions
            ADD CONSTRAINT emr_imm_sub_status_check
            CHECK (status IN ('generated','submitted','acknowledged','rejected'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_immunization_submissions');
    }
};
