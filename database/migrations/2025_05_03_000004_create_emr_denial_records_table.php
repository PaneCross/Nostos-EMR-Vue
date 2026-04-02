<?php

// Migration 105 — emr_denial_records
//
// Tracks denied claims through the denial management workflow.
// Created automatically by Process835RemittanceJob when a RemittanceClaim
// has claim_status = 'denied', or manually by finance staff.
//
// Denial lifecycle:
//   open → appealing → won | lost | written_off
//
// CMS Medicare appeal deadline: 120 days from denial date (42 CFR §405.942).
// The appeal_deadline is auto-set from the remittance claim's remittance_date.
//
// Categories are inferred from CARC codes in the adjustment records:
//   authorization — CARC 96, 197, 277
//   coding_error  — CARC 4, 16, 18, 97, 177
//   timely_filing — CARC 29
//   duplicate     — CARC 18
//   medical_necessity — CARC 50, 57, 167
//   coordination_of_benefits — CARC 22, 23
//   other         — anything else

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_denial_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('remittance_claim_id');
            $table->foreign('remittance_claim_id')
                ->references('id')->on('emr_remittance_claims')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants')->cascadeOnDelete();

            // Optional FK to the encounter that was denied (may not be matched yet)
            $table->unsignedBigInteger('encounter_log_id')->nullable();
            $table->foreign('encounter_log_id')
                ->references('id')->on('emr_encounter_log')
                ->nullOnDelete();

            // Denial classification
            $table->string('denial_category');
            $table->string('status')->default('open');

            // Financial impact
            $table->decimal('denied_amount', 12, 2);

            // Primary CARC code driving the denial (from CAS segments)
            $table->string('primary_reason_code', 10)->nullable();

            // Human-readable denial reason (parsed from CARC description or payer remittance)
            $table->text('denial_reason')->nullable();

            // CMS Medicare appeal deadline: denial_date + 120 days (42 CFR §405.942)
            $table->date('denial_date');
            $table->date('appeal_deadline')->nullable();

            // Appeal workflow tracking
            $table->date('appeal_submitted_date')->nullable();
            $table->text('appeal_notes')->nullable();
            $table->date('resolution_date')->nullable();
            $table->text('resolution_notes')->nullable();

            // Write-off tracking (approved by finance manager)
            $table->unsignedBigInteger('written_off_by_user_id')->nullable();
            $table->foreign('written_off_by_user_id')->references('id')->on('shared_users')->nullOnDelete();
            $table->timestamp('written_off_at')->nullable();

            // Assignment for follow-up
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->foreign('assigned_to_user_id')->references('id')->on('shared_users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        // PostgreSQL CHECK constraints for controlled vocabulary
        DB::statement("ALTER TABLE emr_denial_records ADD CONSTRAINT emr_denial_records_status_check CHECK (status IN ('open', 'appealing', 'won', 'lost', 'written_off'))");
        DB::statement("ALTER TABLE emr_denial_records ADD CONSTRAINT emr_denial_records_category_check CHECK (denial_category IN ('authorization', 'coding_error', 'timely_filing', 'duplicate', 'medical_necessity', 'coordination_of_benefits', 'other'))");

        // Indexes for denial management dashboard and aging reports
        DB::statement('CREATE INDEX emr_denial_records_tenant_status_idx ON emr_denial_records (tenant_id, status)');
        DB::statement('CREATE INDEX emr_denial_records_tenant_category_idx ON emr_denial_records (tenant_id, denial_category)');
        DB::statement('CREATE INDEX emr_denial_records_deadline_idx ON emr_denial_records (appeal_deadline) WHERE status IN (\'open\', \'appealing\')');
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_denial_records');
    }
};
