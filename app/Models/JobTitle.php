<?php

// ─── JobTitle ────────────────────────────────────────────────────────────────
// Org-controlled vocabulary of job titles (RN, LPN, MD, NP, Driver, etc.)
// Defined per tenant in Org Settings → Job Titles.
//
// Used by:
//   - User.job_title : nullable string referencing JobTitle.code
//   - CredentialDefinitionTarget : target_kind='job_title' rows
//
// Soft-deleted (no hard delete) so historical user.job_title strings remain
// readable even after a title is retired.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobTitle extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_job_titles';

    protected $fillable = [
        'tenant_id',
        'code',
        'label',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
