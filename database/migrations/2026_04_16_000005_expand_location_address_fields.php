<?php

// ─── Migration: expand emr_locations address fields ──────────────────────────
// Adds detailed sub-address fields needed for real PACE visits: apartments,
// suites, buildings, floors. The existing `unit` column stays for backward
// compatibility but is supplemented by more specific columns.
//
// Also adds a case-insensitive unique-ish index to help catch accidental
// duplicate location entries on the same street/city combination.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_locations', function (Blueprint $table) {
            // Widen street to accommodate longer formal addresses
            $table->string('street')->nullable()->change();
            // Apartment / suite / building / floor — more granular than the
            // single `unit` column. All optional and all stored as-written for
            // driver clarity (don't try to normalize "Apt 5B" etc.).
            $table->string('apartment', 30)->nullable()->after('unit');
            $table->string('suite', 30)->nullable()->after('apartment');
            $table->string('building', 100)->nullable()->after('suite');
            $table->string('floor', 30)->nullable()->after('building');
            // Access notes — gate codes, parking, "ring buzzer 12", etc. Visible
            // to drivers and staff planning visits.
            $table->text('access_notes')->nullable()->after('floor');
        });

        // Case-insensitive index on (tenant_id, lower(street), lower(city)) for
        // efficient duplicate detection at validation time.
        DB::statement(
            "CREATE INDEX IF NOT EXISTS emr_locations_dupcheck_idx
             ON emr_locations (tenant_id, lower(street), lower(city))"
        );
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS emr_locations_dupcheck_idx");
        Schema::table('emr_locations', function (Blueprint $table) {
            $table->dropColumn(['apartment', 'suite', 'building', 'floor', 'access_notes']);
        });
    }
};
