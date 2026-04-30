<?php

// ─── ChatChannelMute ─────────────────────────────────────────────────────────
// Per-channel mute / snooze for one user.
//
// snoozed_until = null  →  indefinite mute
// snoozed_until = future timestamp → snooze that auto-expires
//
// Resolved at notification dispatch time : if a row exists and snoozed_until
// is null OR in the future, suppress the notification — UNLESS the message
// contains an @mention of the user (any of the 4 forms : @user / @role /
// @dept / @all). @mentions ALWAYS override mute.
//
// See docs/plans/chat_v2_plan.md §3.7 + §11.3.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatChannelMute extends Model
{
    protected $table = 'emr_chat_channel_mutes';

    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    protected $fillable = ['channel_id', 'user_id', 'muted_at', 'snoozed_until'];

    protected $casts = [
        'muted_at'      => 'datetime',
        'snoozed_until' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ChatChannel::class, 'channel_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * True if this mute row is currently suppressing notifications.
     * Indefinite mutes (snoozed_until = null) are always active ; snoozed
     * mutes are active only while now() < snoozed_until.
     */
    public function isActive(): bool
    {
        return $this->snoozed_until === null
            || $this->snoozed_until->isFuture();
    }

    /** Active mutes only (for the "do not notify" lookup at dispatch). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('snoozed_until')
              ->orWhere('snoozed_until', '>', now());
        });
    }
}
