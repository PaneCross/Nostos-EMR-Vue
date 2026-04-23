<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Phase 15.3 — User-built custom report definition.
class ReportDefinition extends Model
{
    protected $table = 'emr_report_definitions';

    public const ENTITIES = [
        'participants', 'medications', 'grievances', 'appointments',
        'incidents', 'care_plans',
    ];

    protected $fillable = [
        'tenant_id', 'created_by_user_id', 'name', 'entity',
        'filters', 'columns', 'group_by',
        'is_shared', 'last_run_at',
    ];

    protected $casts = [
        'filters'      => 'array',
        'columns'      => 'array',
        'group_by'     => 'array',
        'is_shared'    => 'boolean',
        'last_run_at'  => 'datetime',
    ];

    public function tenant(): BelongsTo   { return $this->belongsTo(Tenant::class); }
    public function creator(): BelongsTo  { return $this->belongsTo(User::class, 'created_by_user_id'); }

    public function scopeForTenant($q, int $tenantId) { return $q->where('tenant_id', $tenantId); }
    public function scopeVisibleTo($q, int $userId)
    {
        return $q->where(function ($w) use ($userId) {
            $w->where('is_shared', true)->orWhere('created_by_user_id', $userId);
        });
    }
}
