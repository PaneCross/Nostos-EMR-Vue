<?php

// ─── ClearinghouseConfig ─────────────────────────────────────────────────────
// Phase 12. Per-tenant configuration for claims clearinghouse integration.
// Defaults to the null_gateway adapter which stages 837P files for manual
// upload : honest-label behavior when no paid vendor contract is in place.
//
// The credentials_json column is encrypted at-rest. Schema + auth managed
// through StateMedicaidConfig-style IT admin UI.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClearinghouseConfig extends Model
{
    protected $table = 'emr_clearinghouse_configs';

    public const ADAPTERS = ['null_gateway', 'availity', 'change_healthcare', 'office_ally', 'custom'];
    public const ADAPTER_LABELS = [
        'null_gateway'      => 'No vendor : manual upload',
        'availity'          => 'Availity',
        'change_healthcare' => 'Change Healthcare',
        'office_ally'       => 'Office Ally',
        'custom'            => 'Custom adapter',
    ];

    public const ENVIRONMENTS = ['sandbox', 'production'];

    protected $fillable = [
        'tenant_id', 'adapter', 'display_name',
        'submitter_id', 'receiver_id', 'endpoint_url',
        'credentials_json', 'environment',
        'submission_timeout_s', 'max_retries', 'retry_backoff_s',
        'notes', 'is_active',
        'last_successful_at', 'last_failed_at', 'last_error',
    ];

    protected $casts = [
        'credentials_json'   => 'encrypted:array',
        'is_active'          => 'boolean',
        'last_successful_at' => 'datetime',
        'last_failed_at'     => 'datetime',
        'submission_timeout_s' => 'integer',
        'max_retries'        => 'integer',
        'retry_backoff_s'    => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function adapterLabel(): string
    {
        return self::ADAPTER_LABELS[$this->adapter] ?? $this->adapter;
    }

    public function isNullGateway(): bool
    {
        return $this->adapter === 'null_gateway';
    }

    public function scopeForTenant($q, int $tenantId)
    {
        return $q->where('tenant_id', $tenantId);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
