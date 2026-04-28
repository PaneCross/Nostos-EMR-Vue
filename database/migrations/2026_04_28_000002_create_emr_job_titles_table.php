<?php

// ─── Migration: emr_job_titles ────────────────────────────────────────────────
// Org-controlled vocabulary of job titles. Defined per tenant in Org Settings →
// Job Titles. Used by credential definitions to target specific licensed roles.
//
// Soft-deletes (no hard delete) so historical user.job_title strings remain
// readable even after a title is retired.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_job_titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();

            $table->string('code', 60);              // e.g. 'rn', 'lpn', 'md'
            $table->string('label', 120);            // e.g. 'Registered Nurse'
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestampsTz();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_job_titles');
    }
};
