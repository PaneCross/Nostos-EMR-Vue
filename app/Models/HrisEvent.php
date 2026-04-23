<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrisEvent extends Model
{
    protected $table = 'emr_hris_events';

    public const STATUSES = ['received', 'staged', 'committed', 'ignored', 'failed'];

    protected $fillable = [
        'tenant_id', 'hris_config_id', 'provider',
        'event_type', 'payload', 'processing_status', 'processing_notes',
        'received_at',
    ];

    protected $casts = [
        'payload'     => 'array',
        'received_at' => 'datetime',
    ];

    public function config(): BelongsTo { return $this->belongsTo(HrisConfig::class, 'hris_config_id'); }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    public function scopeForTenant($q, int $tenantId) { return $q->where('tenant_id', $tenantId); }
}
