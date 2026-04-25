<?php

// Phase R7 — optimistic-concurrency revision counter on emr_idt_meetings.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('emr_idt_meetings', function (Blueprint $t) {
            $t->unsignedInteger('revision')->default(0)->after('status');
            $t->timestamp('last_edited_at')->nullable()->after('revision');
            $t->unsignedBigInteger('last_edited_by_user_id')->nullable()->after('last_edited_at');
            $t->foreign('last_edited_by_user_id')->references('id')->on('shared_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('emr_idt_meetings', function (Blueprint $t) {
            $t->dropForeign(['last_edited_by_user_id']);
            $t->dropColumn(['revision', 'last_edited_at', 'last_edited_by_user_id']);
        });
    }
};
