<?php

// ─── Migration: emr_chat_message_pins ────────────────────────────────────────
// One row per pinned message. The UNIQUE constraint on message_id enforces
// "a message is either pinned once or not pinned" — toggling pin state is
// insert / delete, not update.
//
// Soft cap of 50 pins per channel is enforced application-side (with admin
// override via Pin anyway, audit-logged as chat.pin_cap_override).
//
// See docs/plans/chat_v2_plan.md §3.6 + §11.7.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_chat_message_pins', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_id')
                ->constrained('emr_chat_channels')
                ->cascadeOnDelete();
            $t->foreignId('message_id')
                ->constrained('emr_chat_messages')
                ->cascadeOnDelete();
            $t->foreignId('pinned_by_user_id')
                ->constrained('shared_users')
                ->cascadeOnDelete();
            $t->timestamp('pinned_at')->useCurrent();

            // A given message is pinned at most once.
            $t->unique('message_id');
            // For the channel's pinned-list query (sorted chronologically by
            // original message time, NOT pin time, so the panel reads as a
            // chronological summary of the channel's important moments).
            $t->index(['channel_id', 'pinned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_chat_message_pins');
    }
};
