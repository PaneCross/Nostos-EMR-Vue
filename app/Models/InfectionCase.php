<?php

// ─── InfectionCase ───────────────────────────────────────────────────────────
// Phase B2. One case per (participant × organism × onset). Links to an
// InfectionOutbreak when part of a cluster (outbreak_id is nullable : some
// cases are isolated community exposures that never cluster).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InfectionCase extends Model
{
    protected $table = 'emr_infection_cases';

    public const COMMON_ORGANISMS = [
        'influenza', 'covid19', 'norovirus', 'c_diff', 'rsv',
        'staph_aureus', 'pseudomonas', 'mrsa', 'vre', 'tuberculosis', 'other',
    ];
    public const SEVERITIES = ['mild', 'moderate', 'severe', 'fatal'];
    public const SOURCES    = ['community', 'facility', 'healthcare', 'unknown'];

    protected $fillable = [
        'tenant_id', 'participant_id', 'site_id', 'outbreak_id',
        'organism_type', 'organism_detail',
        'onset_date', 'resolution_date', 'severity',
        'hospitalization_required',
        'isolation_started_at', 'isolation_ended_at',
        'source', 'reported_to_state_at', 'reported_by_user_id',
        'notes',
    ];

    protected $casts = [
        'onset_date'                => 'date',
        'resolution_date'           => 'date',
        'hospitalization_required'  => 'boolean',
        'isolation_started_at'      => 'datetime',
        'isolation_ended_at'        => 'datetime',
        'reported_to_state_at'      => 'datetime',
    ];

    public function tenant(): BelongsTo       { return $this->belongsTo(Tenant::class); }
    public function participant(): BelongsTo  { return $this->belongsTo(Participant::class); }
    public function site(): BelongsTo         { return $this->belongsTo(Site::class); }
    public function outbreak(): BelongsTo     { return $this->belongsTo(InfectionOutbreak::class, 'outbreak_id'); }
    public function reportedBy(): BelongsTo   { return $this->belongsTo(User::class, 'reported_by_user_id'); }

    public function scopeForTenant($q, int $tenantId) { return $q->where('tenant_id', $tenantId); }
    public function scopeActiveInfection($q)
    {
        return $q->whereNull('resolution_date');
    }
}
