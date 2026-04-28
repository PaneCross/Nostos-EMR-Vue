<?php

// ─── CredentialDefinition ────────────────────────────────────────────────────
// Org-level catalog row describing a credential the org tracks. CMS-mandatory
// rows are seeded by CmsCredentialBaselineSeeder and cannot be deleted or
// disabled at the org level (only at the per-site level via overrides, AND
// only for non-mandatory rows).
//
// Targeting (which users this applies to) lives in CredentialDefinitionTarget
// rows : OR semantics across (department, job_title, designation) tuples.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CredentialDefinition extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_credential_definitions';

    /** Default fall-back cadence (days before expiration) when none configured. */
    public const DEFAULT_CADENCE = [90, 30, 14, 0];

    protected $fillable = [
        'tenant_id',
        'site_id',
        'code',
        'title',
        'credential_type',
        'description',
        'requires_psv',
        'is_cms_mandatory',
        'default_doc_required',
        'reminder_cadence_days',
        'ceu_hours_required',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'requires_psv'           => 'boolean',
        'is_cms_mandatory'       => 'boolean',
        'default_doc_required'   => 'boolean',
        'is_active'              => 'boolean',
        'reminder_cadence_days'  => 'array',
        'ceu_hours_required'     => 'integer',
        'sort_order'             => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(CredentialDefinitionTarget::class, 'credential_definition_id');
    }

    public function siteOverrides(): HasMany
    {
        return $this->hasMany(CredentialDefinitionSiteOverride::class, 'credential_definition_id');
    }

    public function staffCredentials(): HasMany
    {
        return $this->hasMany(StaffCredential::class, 'credential_definition_id');
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function effectiveCadence(): array
    {
        $cadence = $this->reminder_cadence_days ?? [];
        return ! empty($cadence) ? $cadence : self::DEFAULT_CADENCE;
    }
}
