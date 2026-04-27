<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Phase 15.2 : Per-tenant SAML 2.0 IdP configuration.
class SamlIdentityProvider extends Model
{
    protected $table = 'emr_saml_identity_providers';

    protected $fillable = [
        'tenant_id', 'display_name', 'entity_id', 'sso_url', 'slo_url',
        'x509_cert', 'sp_entity_id', 'name_id_format', 'attribute_mapping',
        'is_active', 'last_login_at',
    ];

    protected $casts = [
        'attribute_mapping' => 'array',
        'is_active'         => 'boolean',
        'last_login_at'     => 'datetime',
    ];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    public function scopeForTenant($q, int $tenantId) { return $q->where('tenant_id', $tenantId); }
    public function scopeActive($q)                   { return $q->where('is_active', true); }
}
