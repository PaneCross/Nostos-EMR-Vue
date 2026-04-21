<?php

// ─── Update emr_disenrollment_records reason check ────────────────────────────
// The original migration (2025_04_01_000002_create_emr_disenrollment_records)
// enforced a legacy reason list: voluntary, involuntary, deceased, moved,
// nf_admission, other. This violated 42 CFR §460.162/§460.164 granularity and
// conflated "deceased" (a reason) with status.
//
// This migration drops the legacy check and re-adds it with the canonical
// CMS-aligned reason enum from App\Support\DisenrollmentTaxonomy::REASONS.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('emr_disenrollment_records')) {
            return;
        }

        DB::statement('ALTER TABLE emr_disenrollment_records DROP CONSTRAINT IF EXISTS emr_disenrollment_records_reason_check');

        DB::statement("
            ALTER TABLE emr_disenrollment_records
            ADD CONSTRAINT emr_disenrollment_records_reason_check
            CHECK (reason IN (
                'death',
                'voluntary_moved_out_of_area',
                'voluntary_dissatisfied',
                'voluntary_elected_hospice_outside_pace',
                'voluntary_other',
                'involuntary_nonpayment_premium',
                'involuntary_nonpayment_medicaid_liability',
                'involuntary_disruptive_participant',
                'involuntary_disruptive_caregiver',
                'involuntary_out_of_service_area',
                'involuntary_loss_of_nf_loc_eligibility',
                'involuntary_program_termination',
                'involuntary_loss_of_licensure'
            ))
        ");

        // Check constraint on disenrollment_type for rollup integrity.
        DB::statement('ALTER TABLE emr_disenrollment_records DROP CONSTRAINT IF EXISTS emr_disenrollment_records_type_check');
        DB::statement("
            ALTER TABLE emr_disenrollment_records
            ADD CONSTRAINT emr_disenrollment_records_type_check
            CHECK (disenrollment_type IS NULL OR disenrollment_type IN ('death', 'voluntary', 'involuntary'))
        ");
    }

    public function down(): void
    {
        if (! Schema::hasTable('emr_disenrollment_records')) {
            return;
        }

        DB::statement('ALTER TABLE emr_disenrollment_records DROP CONSTRAINT IF EXISTS emr_disenrollment_records_reason_check');
        DB::statement('ALTER TABLE emr_disenrollment_records DROP CONSTRAINT IF EXISTS emr_disenrollment_records_type_check');

        // Restore the legacy constraint so down() mirrors the original schema.
        DB::statement("
            ALTER TABLE emr_disenrollment_records
            ADD CONSTRAINT emr_disenrollment_records_reason_check
            CHECK (reason IN ('voluntary', 'involuntary', 'deceased', 'moved', 'nf_admission', 'other'))
        ");
    }
};
