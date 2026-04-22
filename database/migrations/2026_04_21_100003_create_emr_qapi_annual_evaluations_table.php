<?php

// ─── Migration: QAPI Annual Evaluation artifacts ─────────────────────────────
// 42 CFR §460.200 requires an annual QAPI evaluation submitted to the governing
// body. Each evaluation compiles that calendar year's QAPI projects + outcomes
// into a signed/reviewed PDF artifact.
//
// One row per tenant + year. Append-only-ish: the PDF + metadata are created,
// then the governing body review stamp is added (governing_body_reviewed_at).
// Regeneration with the same (tenant, year) pair updates the PDF reference.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_qapi_annual_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $table->integer('year');

            $table->timestampTz('generated_at');
            $table->foreignId('generated_by_user_id')->constrained('shared_users')->restrictOnDelete();
            // Tenant-level artifact — stored directly in local storage (not emr_documents,
            // which is participant-scoped).
            $table->string('pdf_path', 500)->nullable();
            $table->integer('pdf_size_bytes')->nullable();

            // Governing body review capture — §460.200.
            $table->timestampTz('governing_body_reviewed_at')->nullable();
            $table->foreignId('governing_body_reviewed_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $table->text('governing_body_notes')->nullable();

            // Snapshot of the metrics at generation time — flat JSON for
            // auditors to see exactly what was reported.
            $table->json('summary_snapshot');

            $table->timestampsTz();

            $table->unique(['tenant_id', 'year'], 'emr_qapi_ann_eval_tenant_year_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_qapi_annual_evaluations');
    }
};
