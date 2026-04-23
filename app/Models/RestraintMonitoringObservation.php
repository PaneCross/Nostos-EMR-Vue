<?php

// ─── RestraintMonitoringObservation ──────────────────────────────────────────
// Phase B1. One observation per check interval on an active restraint
// episode. Nursing records skin integrity, circulation, mental status, and
// care offered (toileting / hydration / repositioning).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestraintMonitoringObservation extends Model
{
    protected $table = 'emr_restraint_monitoring_observations';

    public const SKIN_VALUES       = ['intact', 'reddened', 'broken', 'other'];
    public const CIRCULATION_VALUES = ['adequate', 'diminished', 'absent'];
    public const MENTAL_VALUES     = ['calm', 'agitated', 'sedated', 'unresponsive', 'other'];

    protected $fillable = [
        'tenant_id', 'restraint_episode_id', 'observed_by_user_id', 'observed_at',
        'skin_integrity', 'circulation', 'mental_status',
        'toileting_offered', 'hydration_offered', 'repositioning_done',
        'notes',
    ];

    protected $casts = [
        'observed_at'         => 'datetime',
        'toileting_offered'   => 'boolean',
        'hydration_offered'   => 'boolean',
        'repositioning_done'  => 'boolean',
    ];

    public function episode(): BelongsTo     { return $this->belongsTo(RestraintEpisode::class, 'restraint_episode_id'); }
    public function observedBy(): BelongsTo  { return $this->belongsTo(User::class, 'observed_by_user_id'); }
}
