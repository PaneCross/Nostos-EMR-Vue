<?php

// ─── Migration: MMR line items ────────────────────────────────────────────────
// One row per member per MMR period. Stores what CMS says about the member
// this period. Reconciliation flags discrepancies vs local roster.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_mmr_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $table->foreignId('mmr_file_id')->constrained('emr_mmr_files')->cascadeOnDelete();

            $table->string('medicare_id', 20);              // MBI as sent by CMS (cleartext)
            $table->string('member_name', 200)->nullable();
            $table->string('member_status', 20);            // active | disenrolled | pending
            $table->date('enrolled_from')->nullable();
            $table->date('enrolled_through')->nullable();

            $table->decimal('capitation_amount', 14, 2)->default(0);
            $table->decimal('adjustment_amount', 14, 2)->default(0);
            $table->json('raw_payload')->nullable();        // original fields as parsed

            // Reconciliation result (computed on parse)
            $table->foreignId('matched_participant_id')->nullable()->constrained('emr_participants')->nullOnDelete();
            $table->string('discrepancy_type', 40)->nullable(); // see ReconciliationService constants
            $table->text('discrepancy_note')->nullable();

            // Resolution tracking
            $table->string('resolution_status', 20)->default('open'); // open | resolved | ignored
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();

            $table->timestampsTz();

            $table->index(['tenant_id', 'mmr_file_id']);
            $table->index(['tenant_id', 'discrepancy_type', 'resolution_status'], 'emr_mmr_discrepancy_idx');
            $table->index(['tenant_id', 'medicare_id'], 'emr_mmr_mbi_idx');
        });

        DB::statement("
            ALTER TABLE emr_mmr_records
            ADD CONSTRAINT emr_mmr_records_resolution_check
            CHECK (resolution_status IN ('open', 'resolved', 'ignored'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_mmr_records');
    }
};
