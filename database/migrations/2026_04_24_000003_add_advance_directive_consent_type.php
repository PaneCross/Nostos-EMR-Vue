<?php

// ─── Phase M1 — advance_directive consent type ──────────────────────────────
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE emr_consent_records DROP CONSTRAINT IF EXISTS emr_consent_records_type_check');
        DB::statement("ALTER TABLE emr_consent_records ADD CONSTRAINT emr_consent_records_type_check CHECK (consent_type IN ('npp_acknowledgment','hipaa_authorization','treatment_consent','research_consent','photo_release','other','advance_directive'))");
    }
    public function down(): void
    {
        DB::statement('ALTER TABLE emr_consent_records DROP CONSTRAINT IF EXISTS emr_consent_records_type_check');
        DB::statement("ALTER TABLE emr_consent_records ADD CONSTRAINT emr_consent_records_type_check CHECK (consent_type IN ('npp_acknowledgment','hipaa_authorization','treatment_consent','research_consent','photo_release','other'))");
    }
};
