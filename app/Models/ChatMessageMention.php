<?php

// ─── ChatMessageMention ──────────────────────────────────────────────────────
// One row per mention parsed out of a message. Four mention forms supported,
// stored mutually exclusively :
//
//   @user.name   →  mentioned_user_id set
//   @role-code   →  mentioned_role_code set    (e.g. @rn)
//   @dept-name   →  mentioned_department set   (e.g. @primary-care)
//   @all         →  is_at_all = true
//
// The server-side parser in ChatService::parseAndStoreMentions() scans the
// message_text on send and inserts these rows. Frontend reads them inline
// with each message to render highlight chips + override mute.
//
// All four forms override the recipient's mute / snooze setting.
//
// See docs/plans/chat_v2_plan.md §3.8 + §11.3.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessageMention extends Model
{
    protected $table = 'emr_chat_message_mentions';

    public const UPDATED_AT = null;

    protected $fillable = [
        'message_id',
        'mentioned_user_id',
        'mentioned_role_code',
        'mentioned_department',
        'is_at_all',
    ];

    protected $casts = [
        'is_at_all'  => 'boolean',
        'created_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function mentionedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentioned_user_id');
    }

    /**
     * Returns one of 'user' / 'role' / 'dept' / 'all' so the frontend can
     * pick the right chip styling without inspecting nullable columns.
     */
    public function kind(): string
    {
        return match (true) {
            $this->is_at_all                 => 'all',
            $this->mentioned_user_id !== null => 'user',
            $this->mentioned_role_code       => 'role',
            $this->mentioned_department      => 'dept',
            default                          => 'unknown',
        };
    }
}
