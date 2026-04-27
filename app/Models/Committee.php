<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Phase 15.8 : Governance / committee root model.
class Committee extends Model
{
    protected $table = 'emr_committees';

    public const TYPES = ['qapi', 'idt_oversight', 'formulary', 'governing_board', 'custom'];

    protected $fillable = [
        'tenant_id', 'name', 'committee_type', 'charter',
        'meeting_cadence', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function tenant(): BelongsTo   { return $this->belongsTo(Tenant::class); }
    public function members(): HasMany    { return $this->hasMany(CommitteeMember::class); }
    public function meetings(): HasMany   { return $this->hasMany(CommitteeMeeting::class); }

    public function scopeForTenant($q, int $tenantId) { return $q->where('tenant_id', $tenantId); }
    public function scopeActive($q)                   { return $q->where('is_active', true); }
}
