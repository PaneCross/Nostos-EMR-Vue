<?php

// ─── CredentialDefinitionSiteOverride ────────────────────────────────────────
// Per-site override on an org-level credential definition. Currently only
// supports 'disabled' (turn the def off for one site). CMS-mandatory rows
// cannot be disabled : the controller enforces this with a 422 response.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CredentialDefinitionSiteOverride extends Model
{
    use HasFactory;

    protected $table = 'emr_credential_definition_site_overrides';

    public const ACTION_DISABLED = 'disabled';

    protected $fillable = [
        'tenant_id',
        'site_id',
        'credential_definition_id',
        'action',
        'updated_by_user_id',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(CredentialDefinition::class, 'credential_definition_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
