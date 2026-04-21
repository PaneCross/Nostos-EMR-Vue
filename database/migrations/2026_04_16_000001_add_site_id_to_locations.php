<?php

// ─── Migration: add site_id to emr_locations ──────────────────────────────────
// Adds nullable FK from emr_locations → shared_sites so the system can
// distinguish PACE-site-owned locations (e.g. East Day Center) from external
// service locations (hospitals, dialysis, pharmacies). Used to detect cross-site
// appointments and route Day Center roster visitors correctly.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_locations', function (Blueprint $table) {
            $table->foreignId('site_id')
                ->nullable()
                ->after('location_type')
                ->constrained('shared_sites')
                ->nullOnDelete();

            $table->index(['tenant_id', 'site_id'], 'emr_locations_tenant_site_idx');
        });
    }

    public function down(): void
    {
        Schema::table('emr_locations', function (Blueprint $table) {
            $table->dropIndex('emr_locations_tenant_site_idx');
            $table->dropConstrainedForeignId('site_id');
        });
    }
};
