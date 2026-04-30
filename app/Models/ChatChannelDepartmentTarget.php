<?php

// ─── ChatChannelDepartmentTarget ─────────────────────────────────────────────
// Pivot row : a role_group channel targets one department.
// Empty for site_wide channels.
//
// See docs/plans/chat_v2_plan.md §4.3.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatChannelDepartmentTarget extends Model
{
    protected $table = 'emr_chat_channel_department_targets';

    public const UPDATED_AT = null;

    protected $fillable = ['channel_id', 'department'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ChatChannel::class, 'channel_id');
    }
}
