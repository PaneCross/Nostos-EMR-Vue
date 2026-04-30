<?php

// ─── Migration: emr_chat_message_reactions ───────────────────────────────────
// Per-message reactions. The 5-emoji palette is stored as semantic codes
// (thumbs_up, check, eyes, heart, question) so we can re-skin the UI
// without a DB migration if accessibility / localization demands it.
//
// One user can have ONE row per (message, reaction) pair. A user CAN
// have multiple distinct reaction types on the same message
// (e.g. 👍 + ❤️) — that's two rows.
//
// See docs/plans/chat_v2_plan.md §3.4.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_chat_message_reactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')
                ->constrained('emr_chat_messages')
                ->cascadeOnDelete();
            $t->foreignId('user_id')
                ->constrained('shared_users')
                ->cascadeOnDelete();
            // Semantic code, not raw emoji. See ChatMessageReaction model
            // for the canonical whitelist.
            $t->string('reaction', 16);
            $t->timestamp('reacted_at')->useCurrent();

            // One reaction of a given type per user per message.
            $t->unique(['message_id', 'user_id', 'reaction']);
            // Drives count-per-reaction queries on message render.
            $t->index(['message_id', 'reaction']);
        });

        // Pin the reaction values at the DB level so a future unauthorized
        // value (typo, malicious mass insert) is rejected.
        DB::statement("ALTER TABLE emr_chat_message_reactions ADD CONSTRAINT chat_reactions_value_check
            CHECK (reaction IN ('thumbs_up', 'check', 'eyes', 'heart', 'question'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_chat_message_reactions');
    }
};
