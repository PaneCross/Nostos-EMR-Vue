<?php

// ─── Migration: SDR standard/expedited type ───────────────────────────────────
// CMS PACE Audit SDDR protocol distinguishes:
//   standard  = 72-hour decision clock (existing behavior)
//   expedited = 24-hour decision clock (harm risk if delayed)
//
// Adds sdr_type column with CHECK constraint. Defaults existing rows to
// 'standard' so the historical 72-hour behavior is preserved.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_sdrs', function (Blueprint $table) {
            $table->string('sdr_type', 20)->default('standard')->after('priority');
        });

        DB::statement("
            ALTER TABLE emr_sdrs
            ADD CONSTRAINT emr_sdrs_type_check
            CHECK (sdr_type IN ('standard', 'expedited'))
        ");

        Schema::table('emr_sdrs', function (Blueprint $table) {
            $table->index(['tenant_id', 'sdr_type'], 'emr_sdrs_tenant_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('emr_sdrs', function (Blueprint $table) {
            $table->dropIndex('emr_sdrs_tenant_type_idx');
        });
        DB::statement('ALTER TABLE emr_sdrs DROP CONSTRAINT IF EXISTS emr_sdrs_type_check');
        Schema::table('emr_sdrs', function (Blueprint $table) {
            $table->dropColumn('sdr_type');
        });
    }
};
