<?php

// ─── StateImmunizationRegistryConfig ──────────────────────────────────────────
// Phase 8 (MVP roadmap). Per-tenant configuration for state IIS (Immunization
// Information System) submissions. Governs VXU message envelope fields and
// captures state companion-guide quirks as free-text. Actual transmission is
// not implemented : this table exists so the Hl7VxuBuilder can stamp the
// right facility/application identifiers and so operational rollout can
// wire real transmission without schema churn.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StateImmunizationRegistryConfig extends Model
{
    protected $table = 'shared_state_immunization_registry_configs';

    public const AUTH_METHODS = ['manual', 'basic', 'oauth', 'sftp_key'];

    protected $fillable = [
        'tenant_id', 'state_code', 'state_name', 'registry_name',
        'submission_endpoint', 'auth_method', 'profile_version',
        'z_segment_notes', 'sender_facility_id', 'sender_application',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
