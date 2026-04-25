<?php

// ─── Phase P7 — telehealth meeting URL ──────────────────────────────────────
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('emr_appointments', function (Blueprint $t) {
            $t->string('meeting_url', 500)->nullable()->after('appointment_type');
            $t->string('meeting_provider', 30)->nullable()->after('meeting_url'); // zoom | doximity | jitsi | other
        });
    }

    public function down(): void
    {
        Schema::table('emr_appointments', function (Blueprint $t) {
            $t->dropColumn(['meeting_url', 'meeting_provider']);
        });
    }
};
