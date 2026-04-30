<?php

// ─── ChatMessagePin ──────────────────────────────────────────────────────────
// One row per pinned message. UNIQUE(message_id) enforces "a message is
// pinned at most once" — toggling pin state is insert / delete, not update.
//
// 50-pin soft cap is enforced application-side in ChatService::pinMessage()
// with admin-only override path (audit-logged as chat.pin_cap_override).
//
// See docs/plans/chat_v2_plan.md §3.6 + §11.7.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessagePin extends Model
{
    protected $table = 'emr_chat_message_pins';

    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    /**
     * Soft cap on pins per channel. Hitting this returns 422 unless the
     * pinning user is a channel admin AND passes ?override=1 (which
     * additionally writes a chat.pin_cap_override audit row).
     */
    public const SOFT_CAP = 50;

    protected $fillable = [
        'channel_id',
        'message_id',
        'pinned_by_user_id',
        'pinned_at',
    ];

    protected $casts = [
        'pinned_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ChatChannel::class, 'channel_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by_user_id');
    }
}
