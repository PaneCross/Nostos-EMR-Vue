<?php

// ─── ChatChannelRoleTarget ───────────────────────────────────────────────────
// Pivot row : a role_group channel targets one JobTitle (by code, not FK).
// One row per (channel_id, job_title_code) ; stored in
// emr_chat_channel_role_targets.
//
// We keep this as a thin model (rather than a raw belongsToMany pivot) so
// we can attach per-row helpers later (e.g. "added by whom", "added when")
// without restructuring.
//
// See docs/plans/chat_v2_plan.md §4.3.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatChannelRoleTarget extends Model
{
    protected $table = 'emr_chat_channel_role_targets';

    // Only created_at is tracked ; no updated_at on this pivot.
    public const UPDATED_AT = null;

    protected $fillable = ['channel_id', 'job_title_code'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ChatChannel::class, 'channel_id');
    }
}
