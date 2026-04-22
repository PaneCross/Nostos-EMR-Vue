<?php

// ─── Migration: Staff Credentials ─────────────────────────────────────────────
// 42 CFR §460.64-§460.71 + CMS Personnel Audit Protocol require PACE
// organizations to track, on every staff member:
//   - Professional licenses (state + expiration)
//   - TB clearance
//   - Required competency evaluations
//   - Certifications (BLS, CPR, dementia care, etc.)
//   - Immunizations (flu, COVID where required)
//
// One row per (user, credential_type, license_state?, license_number?).
// Expired credentials remain in the table as audit evidence.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_staff_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('shared_users')->cascadeOnDelete();

            $table->string('credential_type', 40);        // license | tb_clearance | training | competency | certification | immunization
            $table->string('title', 200);                 // "RN License", "TB Test (2-step)", "BLS Certification"

            // License-specific (nullable for non-license types)
            $table->string('license_state', 2)->nullable();
            $table->string('license_number', 80)->nullable();

            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();

            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();

            // Optional supporting document — shared_users, not participant-scoped,
            // so we reference by path (not the emr_documents table which requires
            // participant_id NOT NULL).
            $table->string('document_path', 500)->nullable();
            $table->string('document_filename', 255)->nullable();

            $table->text('notes')->nullable();

            $table->timestampsTz();
            $table->softDeletes();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'expires_at'], 'emr_staff_credentials_expiry_idx');
            $table->index(['tenant_id', 'credential_type'], 'emr_staff_credentials_type_idx');
        });

        DB::statement("
            ALTER TABLE emr_staff_credentials
            ADD CONSTRAINT emr_staff_credentials_type_check
            CHECK (credential_type IN (
                'license',
                'tb_clearance',
                'training',
                'competency',
                'certification',
                'immunization',
                'background_check',
                'other'
            ))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_staff_credentials');
    }
};
