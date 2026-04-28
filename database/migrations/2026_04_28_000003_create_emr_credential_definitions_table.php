<?php

// ─── Migration: emr_credential_definitions ────────────────────────────────────
// Org-level catalog of credential types tracked for staff. Executive defines
// these via Org Settings → Credentials. CMS-mandatory rows (seeded by
// CmsCredentialBaselineSeeder) cannot be deleted or disabled at the org level.
//
// Per-site overrides live in emr_credential_definition_site_overrides:
//   - 'disabled' override hides a non-mandatory definition for one site
//   - 'extra'    override flag is informational (creating a site-only def)
//
// Targeting rules live in emr_credential_definition_targets (one row per
// target_kind+target_value tuple, OR semantics — a user is targeted if ANY
// rule matches their dept / job_title / designation).
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_credential_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $table->foreignId('site_id')
                ->nullable()
                ->constrained('shared_sites')
                ->nullOnDelete();   // null = org-level definition; set = site-only extra

            $table->string('code', 80);                       // unique-ish key, e.g. 'rn_license', 'bls'
            $table->string('title', 200);                     // e.g. 'RN License'
            $table->string('credential_type', 40);            // matches emr_staff_credentials.credential_type enum
            $table->text('description')->nullable();

            $table->boolean('requires_psv')->default(false);  // primary source verification required (§460.64(c))
            $table->boolean('is_cms_mandatory')->default(false); // seeded baseline; cannot be deleted/disabled
            $table->boolean('default_doc_required')->default(false); // PDF upload required at create

            // Reminder cadence in days-before-expiration : e.g. [90,30,14,0]
            // 0 = day-of, negatives = post-expiration overdue follow-ups
            $table->jsonb('reminder_cadence_days')->default('[90,30,14,0]');

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestampsTz();
            $table->softDeletes();

            $table->unique(['tenant_id', 'site_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'credential_type']);
        });

        DB::statement("
            ALTER TABLE emr_credential_definitions
            ADD CONSTRAINT emr_credential_definitions_type_check
            CHECK (credential_type IN (
                'license',
                'tb_clearance',
                'training',
                'competency',
                'certification',
                'immunization',
                'background_check',
                'driver_record',
                'other'
            ))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_credential_definitions');
    }
};
