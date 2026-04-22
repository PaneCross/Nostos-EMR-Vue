<?php

// ─── FhirBulkExportJob ───────────────────────────────────────────────────────
// Phase 15.1. Tracks an async FHIR Bulk Data Access ($export) job.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FhirBulkExportJob extends Model
{
    protected $table = 'emr_fhir_bulk_export_jobs';

    public const STATUSES = ['accepted', 'in_progress', 'complete', 'failed', 'cancelled'];

    protected $fillable = [
        'tenant_id', 'api_token_id', 'status', 'resource_types', 'since',
        'output_format', 'progress_pct', 'manifest_json',
        'error_message', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'since'        => 'datetime',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'progress_pct' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function apiToken(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class);
    }

    public function resourceTypesArray(): array
    {
        if (empty($this->resource_types)) return [];
        return array_values(array_filter(explode('|', $this->resource_types)));
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['complete', 'failed', 'cancelled'], true);
    }

    public function scopeForTenant($q, int $tenantId)
    {
        return $q->where('tenant_id', $tenantId);
    }
}
