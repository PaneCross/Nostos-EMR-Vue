<?php

// ─── Migration: Level I / Level II quarterly reporting submissions ────────────
// CMS PACE Reporting & Monitoring Requirements (Level I / Level II) mandate
// quarterly submission of: mortality, hospital utilization, ER visits,
// falls with injury, pressure injuries (stage 2+), immunization rates,
// burns, infectious disease outbreaks.
//
// One row per tenant + (year, quarter). Idempotent regeneration preserves
// the "submitted" stamp — only refreshes CSV + indicator snapshot.
//
// Honest-labeling pattern (feedback_cms_labeling_standard.md): there is no
// real CMS HPMS transmission wired yet — staff marks as "CMS Submitted"
// to record the upload timestamp in the audit log.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_level_ii_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();

            $table->integer('year');
            $table->smallInteger('quarter'); // 1..4

            $table->timestampTz('generated_at');
            $table->foreignId('generated_by_user_id')->constrained('shared_users')->restrictOnDelete();

            // CSV artifact on local disk (tenant-level, not attached to a participant).
            $table->string('csv_path', 500)->nullable();
            $table->integer('csv_size_bytes')->nullable();

            // Honest submission tracker (no real CMS transmission yet).
            $table->timestampTz('marked_cms_submitted_at')->nullable();
            $table->foreignId('marked_cms_submitted_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $table->text('marked_cms_submitted_notes')->nullable();

            // Point-in-time snapshot of indicator values for auditor reference.
            $table->json('indicators_snapshot');

            $table->timestampsTz();

            $table->unique(['tenant_id', 'year', 'quarter'], 'emr_level_ii_tenant_year_q_uniq');
        });

        DB::statement("
            ALTER TABLE emr_level_ii_submissions
            ADD CONSTRAINT emr_level_ii_quarter_check
            CHECK (quarter BETWEEN 1 AND 4)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_level_ii_submissions');
    }
};
