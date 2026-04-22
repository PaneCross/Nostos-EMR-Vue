<?php

// ─── Migration: CMS MMR (Monthly Membership Report) file ingest ───────────────
// 42 CFR §422 Subpart K analog for PACE (CMS Payment Methodology, 2005 rule).
// The MMR is CMS's monthly statement of every member they consider enrolled
// in the plan, along with the capitation amount owed. PACE finance must
// reconcile MMR vs local roster to catch:
//   1. CMS enrolled, locally disenrolled (refund may be owed)
//   2. Locally enrolled, not on MMR (missing capitation revenue)
//   3. Capitation amount variance vs expected
//   4. Retroactive adjustments (prior-period corrections)
//
// Honest-labeling: the file format used here is a documented pipe-delimited
// subset that matches our outbound HPMS structure. Real CMS HPMS MMR format
// is behind the CMS portal; Phase 12 will adapter-swap for the real spec.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_mmr_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('shared_users')->restrictOnDelete();

            $table->integer('period_year');
            $table->smallInteger('period_month');           // 1..12
            $table->string('contract_id', 20)->nullable(); // H-Number from file header

            $table->string('original_filename', 255);
            $table->string('storage_path', 500);
            $table->integer('file_size_bytes');

            $table->timestampTz('received_at');
            $table->timestampTz('parsed_at')->nullable();
            $table->string('status', 30)->default('received'); // received | parsing | parsed | parse_error

            $table->integer('record_count')->default(0);
            $table->integer('discrepancy_count')->default(0);
            $table->decimal('total_capitation_amount', 14, 2)->nullable();

            $table->text('parse_error_message')->nullable();

            $table->timestampsTz();

            $table->index(['tenant_id', 'period_year', 'period_month'], 'emr_mmr_files_period_idx');
        });

        DB::statement("
            ALTER TABLE emr_mmr_files
            ADD CONSTRAINT emr_mmr_files_month_check CHECK (period_month BETWEEN 1 AND 12)
        ");
        DB::statement("
            ALTER TABLE emr_mmr_files
            ADD CONSTRAINT emr_mmr_files_status_check
            CHECK (status IN ('received', 'parsing', 'parsed', 'parse_error'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_mmr_files');
    }
};
