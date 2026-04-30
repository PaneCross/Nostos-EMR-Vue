<?php

// ─── Migration: emr_chat_message_mentions ────────────────────────────────────
// One row per mention parsed out of a message. Four mention forms supported :
//
//   @user.name   →  mentioned_user_id set, others null
//   @role-code   →  mentioned_role_code set, others null    (e.g. @rn)
//   @dept-name   →  mentioned_department set, others null   (e.g. @primary-care)
//   @all/@channel →  is_at_all = true, all others null
//
// Server-side parser scans message_text on send and inserts the rows.
// Frontend reads these to render highlights + override mute logic.
//
// All four forms override the recipient's mute / snooze setting.
//
// See docs/plans/chat_v2_plan.md §3.8 + §11.3.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_chat_message_mentions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')
                ->constrained('emr_chat_messages')
                ->cascadeOnDelete();

            // Exactly one of these four is non-null per row :
            $t->foreignId('mentioned_user_id')
                ->nullable()
                ->constrained('shared_users')
                ->cascadeOnDelete();
            // Matches User.job_title (JobTitle.code).
            $t->string('mentioned_role_code', 60)->nullable();
            // Matches User.department.
            $t->string('mentioned_department', 30)->nullable();
            // @all / @channel mention.
            $t->boolean('is_at_all')->default(false);

            $t->timestamp('created_at')->useCurrent();

            // For "highlight messages that mention me" queries.
            $t->index(['mentioned_user_id', 'message_id']);
            // For "force-notify all RNs in this channel" lookups.
            $t->index('mentioned_role_code');
            // For "force-notify all primary_care users" lookups.
            $t->index('mentioned_department');
            // For loading mentions inline with messages.
            $t->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_chat_message_mentions');
    }
};
