<?php

// ─── Phase P3 — extend portal_requests_type_chk to include 'amendment' ─────
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE emr_portal_requests DROP CONSTRAINT IF EXISTS portal_requests_type_chk');
        DB::statement("ALTER TABLE emr_portal_requests ADD CONSTRAINT portal_requests_type_chk CHECK (request_type IN ('records','appointment','contact_update','amendment'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE emr_portal_requests DROP CONSTRAINT IF EXISTS portal_requests_type_chk');
        DB::statement("ALTER TABLE emr_portal_requests ADD CONSTRAINT portal_requests_type_chk CHECK (request_type IN ('records','appointment','contact_update'))");
    }
};
