<?php

// ─── Normalize Disenrollment Taxonomy ─────────────────────────────────────────
// Per 42 CFR §460.160-§460.164 and CMS PACE Manual Ch. 4:
//   - Death is NOT a top-level enrollment status. It is a disenrollment reason.
//   - Disenrollments roll up into 3 types: voluntary | involuntary | death.
//
// This migration:
//   1. Adds `disenrollment_type` column (voluntary | involuntary | death) to
//      emr_participants and emr_disenrollment_records.
//   2. Backfills existing rows where enrollment_status = 'deceased' →
//      status='disenrolled', reason='death', type='death'.
//   3. Backfills disenrollment_type for existing disenrolled rows by
//      inferring from the reason string.
//
// Kept deliberately NON-destructive: does not drop the legacy 'deceased'
// possibility from the status column (it's a varchar, not a DB enum). The
// app-layer validation (StoreParticipantRequest / UpdateParticipantRequest /
// frontend) is what enforces the new canonical set going forward.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Add disenrollment_type column on participants ────────────────
        if (! Schema::hasColumn('emr_participants', 'disenrollment_type')) {
            Schema::table('emr_participants', function (Blueprint $table) {
                $table->string('disenrollment_type', 20)->nullable()->after('disenrollment_reason');
                $table->index(['tenant_id', 'disenrollment_type'], 'emr_participants_tenant_distype_idx');
            });
        }

        // ── 2. Add disenrollment_type column on disenrollment_records ───────
        if (Schema::hasTable('emr_disenrollment_records')
            && ! Schema::hasColumn('emr_disenrollment_records', 'disenrollment_type')) {
            Schema::table('emr_disenrollment_records', function (Blueprint $table) {
                $table->string('disenrollment_type', 20)->nullable()->after('reason');
                $table->index(['tenant_id', 'disenrollment_type'], 'emr_disenrollment_records_tenant_type_idx');
            });
        }

        // ── 3. Backfill enrollment_status='deceased' rows ───────────────────
        // Convert any participant with status='deceased' to the canonical form:
        //   status=disenrolled, reason=death, type=death
        // disenrollment_date is preserved if present; if missing, we leave it
        // null rather than fabricate — staff will see the data gap and can fix.
        DB::statement("
            UPDATE emr_participants
            SET enrollment_status   = 'disenrolled',
                disenrollment_reason = COALESCE(NULLIF(disenrollment_reason, ''), 'death'),
                disenrollment_type   = 'death'
            WHERE enrollment_status = 'deceased'
        ");

        // ── 4. Backfill disenrollment_type on already-disenrolled rows ──────
        // Infer from the existing reason string. Handles legacy values like
        // 'deceased', 'death', and any voluntary_*/involuntary_* prefixes.
        DB::statement("
            UPDATE emr_participants
            SET disenrollment_type = CASE
                WHEN disenrollment_reason IN ('death', 'deceased')            THEN 'death'
                WHEN disenrollment_reason LIKE 'voluntary_%'                   THEN 'voluntary'
                WHEN disenrollment_reason LIKE 'involuntary_%'                 THEN 'involuntary'
                WHEN disenrollment_reason IN ('voluntary')                     THEN 'voluntary'
                WHEN disenrollment_reason IN ('involuntary')                   THEN 'involuntary'
                ELSE NULL
            END
            WHERE enrollment_status = 'disenrolled'
              AND disenrollment_type IS NULL
        ");

        // Same backfill on emr_disenrollment_records
        if (Schema::hasTable('emr_disenrollment_records')) {
            DB::statement("
                UPDATE emr_disenrollment_records
                SET disenrollment_type = CASE
                    WHEN reason IN ('death', 'deceased')   THEN 'death'
                    WHEN reason LIKE 'voluntary_%'          THEN 'voluntary'
                    WHEN reason LIKE 'involuntary_%'        THEN 'involuntary'
                    WHEN reason = 'voluntary'               THEN 'voluntary'
                    WHEN reason = 'involuntary'             THEN 'involuntary'
                    ELSE NULL
                END
                WHERE disenrollment_type IS NULL
            ");

            // Normalize legacy 'deceased' reason strings to 'death' to match
            // the new canonical reason enum used going forward.
            DB::statement("
                UPDATE emr_disenrollment_records
                SET reason = 'death'
                WHERE reason = 'deceased'
            ");
        }

        // Normalize participant.disenrollment_reason = 'deceased' → 'death' too
        DB::statement("
            UPDATE emr_participants
            SET disenrollment_reason = 'death'
            WHERE disenrollment_reason = 'deceased'
        ");
    }

    public function down(): void
    {
        // Drop the new column but DO NOT revert the data changes. The data
        // changes (deceased→disenrolled+death) match CMS-canonical form and
        // reverting would reintroduce the non-compliant schema.
        if (Schema::hasTable('emr_disenrollment_records')
            && Schema::hasColumn('emr_disenrollment_records', 'disenrollment_type')) {
            Schema::table('emr_disenrollment_records', function (Blueprint $table) {
                $table->dropIndex('emr_disenrollment_records_tenant_type_idx');
                $table->dropColumn('disenrollment_type');
            });
        }
        if (Schema::hasColumn('emr_participants', 'disenrollment_type')) {
            Schema::table('emr_participants', function (Blueprint $table) {
                $table->dropIndex('emr_participants_tenant_distype_idx');
                $table->dropColumn('disenrollment_type');
            });
        }
    }
};
