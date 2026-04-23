<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase B4 — BCMA (barcode medication administration) schema ─────────────
// Adds:
//   - barcode_value on emr_participants (unique per tenant)
//   - barcode_value on emr_medications (optional pharmacy label match)
//   - four scan-tracking columns on emr_emar_records:
//       barcode_scanned_participant_at, barcode_scanned_med_at,
//       barcode_mismatch_overridden_by_user_id, barcode_override_reason_text
//
// Values are backfilled by the `bcma:backfill-barcodes` artisan command
// (app/Console/Commands/BcmaBackfillBarcodesCommand.php).
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::table('emr_participants', function (Blueprint $t) {
            $t->string('barcode_value', 64)->nullable()->after('mrn');
            $t->unique(['tenant_id', 'barcode_value'], 'emr_participants_barcode_tenant_uniq');
        });

        Schema::table('emr_medications', function (Blueprint $t) {
            $t->string('barcode_value', 64)->nullable()->after('drug_name');
            $t->index(['tenant_id', 'barcode_value'], 'emr_medications_barcode_idx');
        });

        Schema::table('emr_emar_records', function (Blueprint $t) {
            $t->timestamp('barcode_scanned_participant_at')->nullable()->after('notes');
            $t->timestamp('barcode_scanned_med_at')->nullable()->after('barcode_scanned_participant_at');
            $t->foreignId('barcode_mismatch_overridden_by_user_id')->nullable()
                ->after('barcode_scanned_med_at')
                ->constrained('shared_users')->nullOnDelete();
            $t->text('barcode_override_reason_text')->nullable()
                ->after('barcode_mismatch_overridden_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('emr_emar_records', function (Blueprint $t) {
            $t->dropConstrainedForeignId('barcode_mismatch_overridden_by_user_id');
            $t->dropColumn([
                'barcode_scanned_participant_at',
                'barcode_scanned_med_at',
                'barcode_override_reason_text',
            ]);
        });
        Schema::table('emr_medications', function (Blueprint $t) {
            $t->dropIndex('emr_medications_barcode_idx');
            $t->dropColumn('barcode_value');
        });
        Schema::table('emr_participants', function (Blueprint $t) {
            $t->dropUnique('emr_participants_barcode_tenant_uniq');
            $t->dropColumn('barcode_value');
        });
    }
};
