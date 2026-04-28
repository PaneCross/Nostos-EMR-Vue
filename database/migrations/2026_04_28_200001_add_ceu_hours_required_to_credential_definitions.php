<?php

// ─── Migration: add ceu_hours_required to credential definitions ─────────────
// Many licenses + certifications require N hours of continuing education per
// renewal cycle (RN: ~30h every 2y, MD: ~50h, MSW: ~30-40h, RD: 75h every 5y).
// This column lets executives configure how many CEU hours are expected for a
// credential's renewal cycle. Combined with StaffCredential::ceuHoursLogged()
// (sums StaffTrainingRecord rows linked via training_records.credential_id),
// the credential row in the UI shows progress like "12 / 30 CEU hrs logged".
//
// 0 (default) = no CEU tracking for this credential.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_credential_definitions', function (Blueprint $table) {
            $table->unsignedSmallInteger('ceu_hours_required')->default(0)->after('reminder_cadence_days');
        });
    }

    public function down(): void
    {
        Schema::table('emr_credential_definitions', function (Blueprint $table) {
            $table->dropColumn('ceu_hours_required');
        });
    }
};
