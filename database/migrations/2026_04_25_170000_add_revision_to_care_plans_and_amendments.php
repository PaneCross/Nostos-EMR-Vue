<?php

// Phase X3 — Audit-12 H3: optimistic-lock revision counter for collaborative
// surfaces. R7 added `revision` to emr_idt_meetings; this extends the same
// pattern to emr_care_plans (multiple disciplines edit during IDT) and
// emr_amendment_requests (concurrency surfaced by Audit-12 H1).
// Both are nullable + default 0 so existing rows are unaffected.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('emr_care_plans', function (Blueprint $t) {
            $t->unsignedInteger('revision')->default(0)->after('status');
            $t->timestamp('last_edited_at')->nullable()->after('revision');
            $t->unsignedBigInteger('last_edited_by_user_id')->nullable()->after('last_edited_at');
            $t->foreign('last_edited_by_user_id')->references('id')->on('shared_users')->nullOnDelete();
        });

        Schema::table('emr_amendment_requests', function (Blueprint $t) {
            $t->unsignedInteger('revision')->default(0)->after('status');
            $t->timestamp('last_edited_at')->nullable()->after('revision');
            $t->unsignedBigInteger('last_edited_by_user_id')->nullable()->after('last_edited_at');
            $t->foreign('last_edited_by_user_id')->references('id')->on('shared_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('emr_care_plans', function (Blueprint $t) {
            $t->dropForeign(['last_edited_by_user_id']);
            $t->dropColumn(['revision', 'last_edited_at', 'last_edited_by_user_id']);
        });
        Schema::table('emr_amendment_requests', function (Blueprint $t) {
            $t->dropForeign(['last_edited_by_user_id']);
            $t->dropColumn(['revision', 'last_edited_at', 'last_edited_by_user_id']);
        });
    }
};
