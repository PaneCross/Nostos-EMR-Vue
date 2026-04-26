<?php

// ─── Phase P3 — HIPAA §164.526 Right to Amend ──────────────────────────────
// Stores patient-initiated amendment requests against any record in the
// designated record set (notes, problem list, allergies, etc.) plus the
// staff queue that must accept/deny within the 60-day deadline.
//
// Why: §164.526 grants individuals the right to request amendments and
// requires the covered entity to act within 60 days (with one 30-day
// extension). Denials must include a written rationale + the patient's
// right to file a statement of disagreement. share_with[] downstream
// notification linkage feeds §164.526(c)(3) (notify those identified
// who received the original info). AmendmentDeadlineJob runs daily.
// CFR ref: 45 CFR §164.526 — right to amend PHI.
// ────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_amendment_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('participant_id');
            $t->unsignedBigInteger('requested_by_portal_user_id')->nullable();

            $t->string('target_record_type', 60)->nullable();   // e.g. ClinicalNote / Problem / Allergy
            $t->unsignedBigInteger('target_record_id')->nullable();
            $t->string('target_field_or_section', 100)->nullable();

            $t->text('requested_change');
            $t->text('justification')->nullable();

            $t->string('status', 20)->default('pending');       // pending | under_review | accepted | denied | withdrawn
            $t->unsignedBigInteger('reviewer_user_id')->nullable();
            $t->timestamp('reviewer_decision_at')->nullable();
            $t->text('decision_rationale')->nullable();

            // §164.526(b)(2) — 60 days to decide; 30-day extension allowed.
            $t->timestamp('deadline_at')->nullable();
            $t->text('patient_disagreement_statement')->nullable();

            $t->timestamps();

            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'participant_id']);
            $t->index('deadline_at');
        });

        DB::statement("ALTER TABLE emr_amendment_requests ADD CONSTRAINT emr_amendment_requests_status_check CHECK (status IN ('pending','under_review','accepted','denied','withdrawn'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_amendment_requests');
    }
};
