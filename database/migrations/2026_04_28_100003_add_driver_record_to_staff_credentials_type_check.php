<?php

// ─── Migration: extend emr_staff_credentials_type_check with driver_record ───
// CredentialDefinitions already supports driver_record (added in 000003) but
// the staff_credentials check constraint predates it. Since driver_record
// definitions exist, the staff rows pointing to them must also accept the
// type. This migration extends the check constraint.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE emr_staff_credentials DROP CONSTRAINT IF EXISTS emr_staff_credentials_type_check');
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
                'driver_record',
                'other'
            ))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE emr_staff_credentials DROP CONSTRAINT IF EXISTS emr_staff_credentials_type_check');
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
};
