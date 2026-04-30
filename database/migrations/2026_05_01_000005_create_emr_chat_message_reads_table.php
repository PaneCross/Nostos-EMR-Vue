<?php

// ─── Migration: emr_chat_message_reads ───────────────────────────────────────
// Per-message read receipts. Distinct from chat_memberships.last_read_at
// which only tracks the channel-level high-water mark for unread badges.
//
// A row is created the first time a user's chat client reports the message
// as visible (Intersection Observer in the Vue layer fires
// POST /chat/channels/{c}/messages/{m}/read). Idempotent: second visibility
// events for the same (message, user) pair are no-ops via the UNIQUE
// constraint.
//
// Each first-read also writes a chat.message_read row to shared_audit_logs
// so HIPAA PHI access is auditable end-to-end.
//
// See docs/plans/chat_v2_plan.md §3.5.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_chat_message_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')
                ->constrained('emr_chat_messages')
                ->cascadeOnDelete();
            $t->foreignId('user_id')
                ->constrained('shared_users')
                ->cascadeOnDelete();
            $t->timestamp('read_at')->useCurrent();

            // First-read only. Subsequent visibility events are absorbed.
            $t->unique(['message_id', 'user_id']);
            // For the "show me what I've read across this channel" path.
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_chat_message_reads');
    }
};
