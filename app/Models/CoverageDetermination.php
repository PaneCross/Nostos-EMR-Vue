<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Phase 15.10 — Participant-specific coverage determination (Part D).
class CoverageDetermination extends Model
{
    protected $table = 'emr_coverage_determinations';

    public const TYPES = [
        'prior_authorization', 'tier_exception', 'quantity_limit_override',
        'step_therapy_override', 'formulary_exception',
    ];
    public const STATUSES = ['pending', 'approved', 'denied', 'withdrawn'];

    protected $fillable = [
        'tenant_id', 'participant_id', 'formulary_entry_id',
        'drug_name', 'rxnorm_code', 'determination_type', 'status',
        'requested_at', 'decided_at',
        'clinical_justification', 'decision_reason',
        'requested_by_user_id', 'decided_by_user_id',
    ];

    protected $casts = [
        'requested_at' => 'date',
        'decided_at'   => 'date',
    ];

    public function tenant(): BelongsTo        { return $this->belongsTo(Tenant::class); }
    public function participant(): BelongsTo   { return $this->belongsTo(Participant::class); }
    public function formularyEntry(): BelongsTo { return $this->belongsTo(FormularyEntry::class); }

    public function scopeForTenant($q, int $tenantId) { return $q->where('tenant_id', $tenantId); }
    public function scopePending($q)                  { return $q->where('status', 'pending'); }
}
