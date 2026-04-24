<?php

// ─── Phase L1 — portal OTP support ──────────────────────────────────────────
// Add participant_portal_user_id (nullable) to shared_otp_codes so the same
// table can issue codes for portal users. user_id becomes nullable.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shared_otp_codes', function (Blueprint $t) {
            $t->unsignedBigInteger('participant_portal_user_id')->nullable()->after('user_id');
        });
        // Make user_id nullable so a row can be owned by either subject.
        DB::statement('ALTER TABLE shared_otp_codes ALTER COLUMN user_id DROP NOT NULL');
        Schema::table('shared_otp_codes', function (Blueprint $t) {
            $t->index('participant_portal_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('shared_otp_codes', function (Blueprint $t) {
            $t->dropIndex(['participant_portal_user_id']);
            $t->dropColumn('participant_portal_user_id');
        });
        DB::statement('ALTER TABLE shared_otp_codes ALTER COLUMN user_id SET NOT NULL');
    }
};
