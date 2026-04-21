<?php

// ─── Migration: add day_center_days to emr_participants ───────────────────────
// Adds a JSONB column storing which weekdays a participant is scheduled to
// attend the day center (e.g., ["mon","wed","fri"]). Used as the baseline
// recurring pattern for the day center roster. Appointments of type
// 'day_center_attendance' override this on a per-day basis (add-ons or swaps).
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_participants', function (Blueprint $table) {
            // Array of weekday codes: mon, tue, wed, thu, fri, sat, sun
            // Null means "no recurring day-center schedule" — participant still
            // appears on roster if they have an explicit appointment that day.
            $table->jsonb('day_center_days')->nullable()->after('nf_certification_date');
        });
    }

    public function down(): void
    {
        Schema::table('emr_participants', function (Blueprint $table) {
            $table->dropColumn('day_center_days');
        });
    }
};
