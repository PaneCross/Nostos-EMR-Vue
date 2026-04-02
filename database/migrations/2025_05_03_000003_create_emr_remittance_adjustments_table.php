<?php

// Migration 104 — emr_remittance_adjustments
//
// Stores CAS (Claim Adjustment Segment) data from X12 835 ERA files.
// Each row represents one CAS segment line, which describes why the payer
// reduced, denied, or adjusted a claim or service line from the submitted amount.
//
// Adjustment groups (X12 standard):
//   CO — Contractual Obligation (payer contract write-off)
//   OA — Other Adjustment
//   PI — Payer Initiated Reductions
//   PR — Patient Responsibility (copay, deductible, coinsurance)
//
// Adjustment reason codes stored here are CARC (Claim Adjustment Reason Codes)
// — standardized X12 values linked to emr_carc_codes lookup table.
//
// Append-only (UPDATED_AT = null) — 835 CAS data is immutable once parsed.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_remittance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('remittance_claim_id');
            $table->foreign('remittance_claim_id')
                ->references('id')->on('emr_remittance_claims')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('shared_tenants')->cascadeOnDelete();

            // CAS01 — Adjustment Group Code
            $table->string('adjustment_group_code', 2); // CO, OA, PI, PR

            // CAS02 — Adjustment Reason Code (CARC)
            // Stored as string to accommodate both numeric ('1', '45') and alphanumeric codes
            $table->string('reason_code', 10);

            // CAS03 — Adjustment Amount (what was written off / adjusted)
            $table->decimal('adjustment_amount', 12, 2);

            // CAS04 — Adjustment Quantity (units adjusted, optional)
            $table->decimal('adjustment_quantity', 8, 2)->nullable();

            // Service line identifier — null means claim-level adjustment (not service-specific)
            // Maps to X12 SVC segment line number within the CLP loop
            $table->string('service_line_id')->nullable();

            // Append-only — CAS data never modified after 835 parsing
            $table->timestamp('created_at')->nullable();
        });

        // Group code CHECK constraint per X12 835 standard
        DB::statement("ALTER TABLE emr_remittance_adjustments ADD CONSTRAINT emr_remittance_adjustments_group_code_check CHECK (adjustment_group_code IN ('CO', 'OA', 'PI', 'PR'))");

        // Index for fast denial analysis queries (finding all CO/PR adjustments by reason code)
        DB::statement('CREATE INDEX emr_remittance_adjustments_claim_idx ON emr_remittance_adjustments (remittance_claim_id)');
        DB::statement('CREATE INDEX emr_remittance_adjustments_reason_idx ON emr_remittance_adjustments (tenant_id, reason_code)');
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_remittance_adjustments');
    }
};
