<?php

// ─── ChatMessageReaction ─────────────────────────────────────────────────────
// One row per (message, user, reaction_code). The 5-emoji palette is stored
// as semantic codes ; the UI maps codes → emoji glyphs at render time.
//
// HIPAA note : reactions are not PHI on their own. They aren't audited
// individually (would be far too noisy). Reaction add/remove broadcasts via
// the MessageReacted Reverb event instead.
//
// See docs/plans/chat_v2_plan.md §3.4 + §4.3.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessageReaction extends Model
{
    protected $table = 'emr_chat_message_reactions';

    // We use reacted_at as the timestamp ; no created_at/updated_at.
    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    /**
     * Whitelisted reaction codes. Mirror of the DB-level CHECK constraint.
     * Any code outside this list is rejected at the FormRequest layer
     * before it even reaches the model.
     */
    public const REACTION_CODES = [
        'thumbs_up',  // 👍
        'check',      // ✅
        'eyes',       // 👀
        'heart',      // ❤️
        'question',   // ❓
    ];

    protected $fillable = ['message_id', 'user_id', 'reaction', 'reacted_at'];

    protected $casts = [
        'reacted_at' => 'datetime',
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
