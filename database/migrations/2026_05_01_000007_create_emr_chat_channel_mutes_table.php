<?php

// ─── Migration: emr_chat_channel_mutes ───────────────────────────────────────
// Per-channel mute / snooze. Resolved at notification dispatch time : if a
// row exists and snoozed_until is null OR in the future, suppress the
// notification — UNLESS the message contains an @mention of the user
// (which always overrides).
//
// snoozed_until = null  → indefinite mute
// snoozed_until = future timestamp → snooze that auto-expires
//
// See docs/plans/chat_v2_plan.md §3.7.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_chat_channel_mutes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_id')
                ->constrained('emr_chat_channels')
                ->cascadeOnDelete();
            $t->foreignId('user_id')
                ->constrained('shared_users')
                ->cascadeOnDelete();
            $t->timestamp('muted_at')->useCurrent();
            // null = indefinite. Future timestamp = snooze.
            $t->timestamp('snoozed_until')->nullable();

            $t->unique(['channel_id', 'user_id']);
            // For the dispatch-time "is this user muted right now?" query.
            $t->index(['user_id', 'snoozed_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_chat_channel_mutes');
    }
};
