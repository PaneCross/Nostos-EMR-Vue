<?php

// ─── Migration: add referral_status to emr_referral_notes ────────────────────
// Captures the referral's pipeline status at the moment the note was written,
// so the thread shows context ("note added when Eligibility Pending") even
// after the referral later transitions. Backfilled for existing rows with
// their current status (approximation; new notes record the true value).
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_referral_notes', function (Blueprint $table) {
            $table->string('referral_status', 30)->nullable()->after('content');
        });

        // Backfill existing notes with their parent referral's current status.
        // Not historically accurate but reasonable for demo data.
        DB::statement("
            UPDATE emr_referral_notes
            SET referral_status = emr_referrals.status
            FROM emr_referrals
            WHERE emr_referral_notes.referral_id = emr_referrals.id
              AND emr_referral_notes.referral_status IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('emr_referral_notes', function (Blueprint $table) {
            $table->dropColumn('referral_status');
        });
    }
};
