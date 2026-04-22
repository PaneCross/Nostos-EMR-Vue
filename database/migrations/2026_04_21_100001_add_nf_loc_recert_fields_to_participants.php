<?php

// ─── Migration: NF-LOC recertification fields ────────────────────────────────
// 42 CFR §460.160(b)(2) requires annual NF level-of-care recertification
// (state may waive for stable participants — track waiver status).
//
// New columns:
//   nf_certification_expires_at — typically nf_certification_date + 1 year;
//                                 stored explicitly so state-specific waiver
//                                 periods (e.g. 2yr in CA) can override.
//   nf_recert_waived            — bool; state has waived annual recert.
//   nf_recert_waived_reason     — free-text rationale for the waiver.
// ──────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_participants', function (Blueprint $table) {
            $table->date('nf_certification_expires_at')->nullable()->after('nf_certification_date');
            $table->boolean('nf_recert_waived')->default(false)->after('nf_certification_expires_at');
            $table->string('nf_recert_waived_reason', 500)->nullable()->after('nf_recert_waived');
        });

        // Backfill expires_at = cert_date + 365 days where cert_date is set.
        DB::statement("
            UPDATE emr_participants
            SET nf_certification_expires_at = (nf_certification_date + INTERVAL '365 days')::date
            WHERE nf_certification_date IS NOT NULL
              AND nf_certification_expires_at IS NULL
        ");

        Schema::table('emr_participants', function (Blueprint $table) {
            $table->index(['tenant_id', 'nf_certification_expires_at'], 'emr_participants_nf_recert_idx');
        });
    }

    public function down(): void
    {
        Schema::table('emr_participants', function (Blueprint $table) {
            $table->dropIndex('emr_participants_nf_recert_idx');
            $table->dropColumn(['nf_certification_expires_at', 'nf_recert_waived', 'nf_recert_waived_reason']);
        });
    }
};
