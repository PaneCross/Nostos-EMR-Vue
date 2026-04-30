<?php

// ─── Migration: extend emr_chat_messages for Chat v2 ─────────────────────────
// Adds two columns to support the edit + soft-delete audit rules :
//
//   original_message_text  : preserved on FIRST edit so the original text
//                            survives in the DB for HIPAA audit even after
//                            edits. Subsequent edits don't update this — it's
//                            the historical record of what was first sent.
//
//   deleted_by_user_id     : who soft-deleted the message (we already had
//                            deleted_at via SoftDeletes ; this captures the
//                            actor for the audit trail).
//
// See docs/plans/chat_v2_plan.md §3.9.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_chat_messages', function (Blueprint $t) {
            // Captured on FIRST edit, never updated by subsequent edits.
            $t->text('original_message_text')->nullable()->after('message_text');
            // Set when soft-delete fires. ChatService::deleteMessage() writes
            // this in the same transaction as the SoftDeletes deleted_at.
            $t->foreignId('deleted_by_user_id')
                ->nullable()
                ->after('deleted_at')
                ->constrained('shared_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('emr_chat_messages', function (Blueprint $t) {
            $t->dropConstrainedForeignId('deleted_by_user_id');
            $t->dropColumn('original_message_text');
        });
    }
};
