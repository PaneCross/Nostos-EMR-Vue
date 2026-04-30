<?php

// ─── ChatMessageRead ─────────────────────────────────────────────────────────
// First-read receipt for a single (message, user) pair. Enforces "first read
// only" via the UNIQUE constraint at the DB level — subsequent visibility
// events for the same pair are absorbed.
//
// Also serves as the audit anchor for HIPAA PHI access : every first read
// fires a chat.message_read row in shared_audit_logs alongside this row.
//
// See docs/plans/chat_v2_plan.md §3.5 + §4.3 + §8.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessageRead extends Model
{
    protected $table = 'emr_chat_message_reads';

    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    protected $fillable = ['message_id', 'user_id', 'read_at'];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
