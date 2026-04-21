<?php

// ─── Migration: add prospective participant fields to emr_referrals ──────────
// Previously the referral only stored the name of the person MAKING the referral
// (referred_by_name). This migration adds the name (and DOB) of the prospective
// PACE participant — the actual person being referred for enrollment.
//
// For existing demo referrals, prospective_first_name/last_name are backfilled
// from the linked participant where available, or left null otherwise.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_referrals', function (Blueprint $table) {
            $table->string('prospective_first_name', 100)->nullable()->after('referred_by_org');
            $table->string('prospective_last_name', 100)->nullable()->after('prospective_first_name');
            $table->date('prospective_dob')->nullable()->after('prospective_last_name');
        });

        // Backfill from linked participant where possible (post-intake referrals)
        DB::statement("
            UPDATE emr_referrals
            SET prospective_first_name = emr_participants.first_name,
                prospective_last_name  = emr_participants.last_name,
                prospective_dob        = emr_participants.dob
            FROM emr_participants
            WHERE emr_referrals.participant_id = emr_participants.id
              AND emr_referrals.prospective_first_name IS NULL
        ");

        // For the remaining demo referrals with no linked participant, use
        // the referred_by_name as a placeholder so the UI isn't blank.
        DB::statement("
            UPDATE emr_referrals
            SET prospective_first_name = split_part(referred_by_name, ' ', 1),
                prospective_last_name  = COALESCE(NULLIF(substring(referred_by_name from position(' ' in referred_by_name) + 1), ''), 'Unknown')
            WHERE prospective_first_name IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('emr_referrals', function (Blueprint $table) {
            $table->dropColumn(['prospective_first_name', 'prospective_last_name', 'prospective_dob']);
        });
    }
};
