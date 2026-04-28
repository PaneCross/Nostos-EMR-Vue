<?php

// ─── Migration: extend emr_staff_credentials ─────────────────────────────────
// Adds linkage to the new catalog + tracking fields for PSV / status overrides
// / renewal chain. Existing free-form rows continue to work; catalog linkage is
// optional (definition_id nullable) so manual one-off entries are still valid.
//
//  - credential_definition_id : optional link to emr_credential_definitions
//  - verification_source      : how the credential was verified (PSV channel)
//  - cms_status               : explicit status override (suspended / revoked
//                               can be set even before expiration date)
//  - replaced_by_credential_id : when a credential is renewed, the new row
//                               points back to the OLD row via this column.
//                               Old row stays in DB as audit history.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_staff_credentials', function (Blueprint $table) {
            $table->foreignId('credential_definition_id')
                ->nullable()
                ->after('credential_type')
                ->constrained('emr_credential_definitions')
                ->nullOnDelete();

            $table->string('verification_source', 30)
                ->nullable()
                ->after('verified_by_user_id');

            $table->string('cms_status', 20)
                ->default('active')
                ->after('verification_source');

            $table->foreignId('replaced_by_credential_id')
                ->nullable()
                ->after('cms_status')
                ->constrained('emr_staff_credentials')
                ->nullOnDelete();

            $table->index(['tenant_id', 'cms_status'], 'emr_staff_credentials_status_idx');
        });

        DB::statement("
            ALTER TABLE emr_staff_credentials
            ADD CONSTRAINT emr_staff_credentials_verification_source_check
            CHECK (verification_source IS NULL OR verification_source IN (
                'state_board',
                'npdb',
                'uploaded_doc',
                'self_attestation',
                'other'
            ))
        ");

        DB::statement("
            ALTER TABLE emr_staff_credentials
            ADD CONSTRAINT emr_staff_credentials_cms_status_check
            CHECK (cms_status IN (
                'active',
                'expired',
                'suspended',
                'revoked',
                'pending'
            ))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE emr_staff_credentials DROP CONSTRAINT IF EXISTS emr_staff_credentials_verification_source_check');
        DB::statement('ALTER TABLE emr_staff_credentials DROP CONSTRAINT IF EXISTS emr_staff_credentials_cms_status_check');

        Schema::table('emr_staff_credentials', function (Blueprint $table) {
            $table->dropForeign(['credential_definition_id']);
            $table->dropForeign(['replaced_by_credential_id']);
            $table->dropIndex('emr_staff_credentials_status_idx');
            $table->dropColumn([
                'credential_definition_id',
                'verification_source',
                'cms_status',
                'replaced_by_credential_id',
            ]);
        });
    }
};
