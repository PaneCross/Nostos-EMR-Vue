<?php

// ─── NotificationPreference ──────────────────────────────────────────────────
// One row per (tenant, preference_key). Governs OPTIONAL alert + workflow
// routing. CMS-required notifications are hardwired in code and do NOT use
// this table : see NotificationPreferenceService::shouldNotify() for the
// short-circuit logic. Mostly read via the service; rarely queried directly.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    use HasFactory;

    protected $table = 'emr_notification_preferences';

    protected $fillable = [
        'tenant_id',
        'site_id',                // null = org-level row; non-null = per-site override
        'preference_key',
        'enabled',
        'value',
        'updated_by_user_id',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'value'   => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
