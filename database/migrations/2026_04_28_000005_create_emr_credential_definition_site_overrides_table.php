<?php

// ─── Migration: emr_credential_definition_site_overrides ──────────────────────
// Per-site overrides on org-level credential definitions.
//
//   action='disabled' : this site does not require this credential (only valid
//                       when the parent definition is_cms_mandatory=false)
//
// Site-only EXTRA definitions are represented directly on
// emr_credential_definitions with site_id set, not via this overrides table.
// This table is purely for "turn an org-level def OFF for this one site."
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_credential_definition_site_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('shared_sites')->cascadeOnDelete();
            $table->foreignId('credential_definition_id')
                ->constrained('emr_credential_definitions')
                ->cascadeOnDelete();

            $table->string('action', 20)->default('disabled'); // 'disabled' (only valid value for now)
            $table->foreignId('updated_by_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->nullOnDelete();

            $table->timestampsTz();

            $table->unique(['site_id', 'credential_definition_id'], 'cred_def_site_override_unique');
        });

        DB::statement("
            ALTER TABLE emr_credential_definition_site_overrides
            ADD CONSTRAINT emr_cred_def_site_override_action_check
            CHECK (action IN ('disabled'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_credential_definition_site_overrides');
    }
};
