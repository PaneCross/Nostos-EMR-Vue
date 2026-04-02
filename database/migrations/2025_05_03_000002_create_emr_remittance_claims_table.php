<?php

// Migration 103 — emr_remittance_claims
//
// Stores individual claim-level adjudication records from X12 835 ERA batches.
// Each claim corresponds to a CLP segment in the 835 file, representing how the
// payer adjudicated a single submitted claim (paid, denied, partial, reversed).
//
// Append-only (UPDATED_AT = null) — 835 data is immutable once parsed.
// Adjudication adjustments stored in emr_remittance_adjustments (CAS segments).
// Denial workflow entries stored in emr_denial_records.
//
// CLP02 claim status mapping:
//   1 → paid_full | 2 → paid_partial | 3 → denied | 4 → reversed
//   19 → pending | 22 → reversed | else → other

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_remittance_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('remittance_batch_id');
            $table->foreign('remittance_batch_id')
                ->references('id')->on('emr_remittance_batches')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants')->cascadeOnDelete();

            // Cross-references to billing tables (nullable — claim may not yet be matched)
            $table->unsignedBigInteger('edi_batch_id')->nullable();
            $table->foreign('edi_batch_id')
                ->references('id')->on('emr_edi_batches')
                ->nullOnDelete();
            $table->unsignedBigInteger('encounter_log_id')->nullable();
            $table->foreign('encounter_log_id')
                ->references('id')->on('emr_encounter_log')
                ->nullOnDelete();

            // X12 835 CLP segment fields
            $table->string('patient_control_number');   // CLP01 — original claim ID submitted
            $table->string('claim_status');             // CLP02 — adjudication outcome

            // Financial amounts (CLP03–CLP05)
            $table->decimal('submitted_amount', 12, 2);   // CLP03 — what we billed
            $table->decimal('allowed_amount', 12, 2);     // CLP04 — what payer allowed
            $table->decimal('paid_amount', 12, 2);        // CLP05 — what payer paid
            $table->decimal('patient_responsibility', 12, 2)->default(0); // copay/deductible

            // Payer's claim identifier (CLP07)
            $table->string('payer_claim_number')->nullable();

            // Service date range from CLM / DTP segments
            $table->date('service_date_from')->nullable();
            $table->date('service_date_to')->nullable();

            // Rendering provider NPI from NM1*82 loop
            $table->string('rendering_provider_npi', 10)->nullable();

            // Date payer processed/adjudicated the claim
            $table->date('remittance_date');

            // Append-only — 835 adjudication data never mutated after parsing
            $table->timestamp('created_at')->nullable();
        });

        // PostgreSQL CHECK constraint for claim_status controlled vocabulary
        DB::statement("ALTER TABLE emr_remittance_claims ADD CONSTRAINT emr_remittance_claims_claim_status_check CHECK (claim_status IN ('paid_full', 'paid_partial', 'denied', 'reversed', 'forwarded', 'pending', 'other'))");

        // Composite indexes for tenant-scoped queries and batch processing
        DB::statement('CREATE INDEX emr_remittance_claims_tenant_status_idx ON emr_remittance_claims (tenant_id, claim_status)');
        DB::statement('CREATE INDEX emr_remittance_claims_batch_idx ON emr_remittance_claims (remittance_batch_id)');
        DB::statement('CREATE INDEX emr_remittance_claims_encounter_idx ON emr_remittance_claims (encounter_log_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_remittance_claims');
    }
};
