<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Phase 15.10 : Per-tenant formulary entry (PACE capitated drug list).
class FormularyEntry extends Model
{
    protected $table = 'emr_formulary_entries';

    public const TIERS = [1, 2, 3, 4, 5];

    protected $fillable = [
        'tenant_id', 'rxnorm_code', 'drug_name', 'generic_name', 'tier',
        'prior_authorization_required', 'quantity_limit', 'quantity_limit_text',
        'step_therapy_required', 'notes', 'is_active',
        'added_by_user_id', 'last_reviewed_at',
    ];

    protected $casts = [
        'prior_authorization_required' => 'boolean',
        'quantity_limit'               => 'boolean',
        'step_therapy_required'        => 'boolean',
        'is_active'                    => 'boolean',
        'tier'                         => 'integer',
        'last_reviewed_at'             => 'datetime',
    ];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    public function scopeForTenant($q, int $tenantId) { return $q->where('tenant_id', $tenantId); }
    public function scopeActive($q)                   { return $q->where('is_active', true); }
}
