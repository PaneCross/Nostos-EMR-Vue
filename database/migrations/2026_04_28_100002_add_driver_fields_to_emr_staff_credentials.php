<?php

// ─── Migration: add driver-specific fields to emr_staff_credentials ──────────
// Three structured fields that only apply when credential_type='driver_record'
// (or 'license' for a driver's CDL). Nullable everywhere else.
//
// FMCSA / DOT compliance for PACE transport:
//   - dot_medical_card_expires_at : DOT physical exam, 2-year cycle
//   - mvr_check_date              : Motor Vehicle Record pull, typically annual
//                                   to triennial depending on state policy
//   - vehicle_class_endorsements  : free text ("Class B + P endorsement",
//                                   "CDL-A with HazMat", etc.)
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_staff_credentials', function (Blueprint $table) {
            $table->date('dot_medical_card_expires_at')->nullable()->after('expires_at');
            $table->date('mvr_check_date')->nullable()->after('dot_medical_card_expires_at');
            $table->string('vehicle_class_endorsements', 200)->nullable()->after('mvr_check_date');
        });
    }

    public function down(): void
    {
        Schema::table('emr_staff_credentials', function (Blueprint $table) {
            $table->dropColumn(['dot_medical_card_expires_at', 'mvr_check_date', 'vehicle_class_endorsements']);
        });
    }
};
