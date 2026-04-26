<?php

// ─── Migration: emr_encounter_log ───────────────────────────────────────────
// Per-encounter clinical activity log. One row = one billable touchpoint
// (PCP visit, day-center attendance, home-care visit, telehealth).
//
// Why: encounters drive CMS Encounter Data Submission (EDS), HEDIS measure
// numerators, and downstream 837P billing. Storing them as a dedicated table
// (rather than inferring from notes/orders) gives a single canonical timeline
// for risk-adjustment lookback, audit pulls, and EDS resubmission.
// Billing fields (CPT, modifiers, place-of-service) are added by a later
// migration (add_billing_fields_to_emr_encounter_log).
// CFR ref: 42 CFR §460.200(b) (data collection); 42 CFR §422.310 (EDS).
// ────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_encounter_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('participant_id');

            $table->date('service_date');
            $table->string('service_type', 100);   // e.g. primary_care, therapy, home_care
            $table->string('procedure_code', 20)->nullable(); // CPT/HCPCS code
            $table->unsignedBigInteger('provider_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();

            // Encounter log is append-only — no SoftDeletes. Meets HIPAA audit requirements.
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('shared_tenants');
            $table->foreign('participant_id')->references('id')->on('emr_participants')->onDelete('cascade');
            $table->foreign('provider_user_id')->references('id')->on('shared_users')->nullOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('shared_users')->nullOnDelete();

            $table->index(['tenant_id', 'service_date']);
            $table->index(['participant_id', 'service_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_encounter_log');
    }
};
