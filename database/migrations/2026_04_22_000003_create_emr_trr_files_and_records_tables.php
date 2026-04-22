<?php

// ─── Migration: CMS TRR (Transaction Reply Report) file ingest ────────────────
// The TRR is CMS's response to transactions the plan submitted (enrollments,
// disenrollments, corrections). Each reply carries:
//   - Transaction code (01=enrollment, 51=disenrollment, etc.)
//   - Result (accepted | rejected | pending | informational)
//   - Transaction Reply Code (TRC) explaining why
//
// Finance + enrollment use this to know what CMS accepted vs rejected.
//
// Same honest-labeling posture as MMR (Phase 12 adapter-swap for real HPMS).
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_trr_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('shared_users')->restrictOnDelete();

            $table->string('contract_id', 20)->nullable();
            $table->string('original_filename', 255);
            $table->string('storage_path', 500);
            $table->integer('file_size_bytes');

            $table->timestampTz('received_at');
            $table->timestampTz('parsed_at')->nullable();
            $table->string('status', 30)->default('received');

            $table->integer('record_count')->default(0);
            $table->integer('rejected_count')->default(0);
            $table->integer('accepted_count')->default(0);

            $table->text('parse_error_message')->nullable();

            $table->timestampsTz();

            $table->index(['tenant_id', 'received_at']);
        });

        DB::statement("
            ALTER TABLE emr_trr_files
            ADD CONSTRAINT emr_trr_files_status_check
            CHECK (status IN ('received', 'parsing', 'parsed', 'parse_error'))
        ");

        Schema::create('emr_trr_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $table->foreignId('trr_file_id')->constrained('emr_trr_files')->cascadeOnDelete();

            $table->string('medicare_id', 20);
            $table->string('transaction_code', 10);         // 01, 51, 61, etc.
            $table->string('transaction_label', 100)->nullable();
            $table->string('transaction_result', 20);       // accepted | rejected | pending | informational
            $table->string('trc_code', 20)->nullable();     // transaction reply code
            $table->text('trc_description')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('transaction_date')->nullable();
            $table->json('raw_payload')->nullable();

            $table->foreignId('matched_participant_id')->nullable()->constrained('emr_participants')->nullOnDelete();

            $table->timestampsTz();

            $table->index(['tenant_id', 'trr_file_id']);
            $table->index(['tenant_id', 'medicare_id'], 'emr_trr_mbi_idx');
            $table->index(['tenant_id', 'transaction_result'], 'emr_trr_result_idx');
        });

        DB::statement("
            ALTER TABLE emr_trr_records
            ADD CONSTRAINT emr_trr_result_check
            CHECK (transaction_result IN ('accepted', 'rejected', 'pending', 'informational'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_trr_records');
        Schema::dropIfExists('emr_trr_files');
    }
};
