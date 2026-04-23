<?php

// ─── InfectionOutbreak ───────────────────────────────────────────────────────
// Phase B2. One outbreak per (site × organism × start-window). Auto-created
// by OutbreakDetectionService when ≥3 cases of the same organism occur at
// the same site within 7 days. Tracks attack rate + containment measures +
// state reporting timestamp.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InfectionOutbreak extends Model
{
    protected $table = 'emr_infection_outbreaks';

    public const STATUSES = ['active', 'contained', 'ended'];

    /** Outbreak-detection threshold: ≥ N cases of the same organism at the
     * same site within the last X days → auto-declare. */
    public const DETECTION_MIN_CASES = 3;
    public const DETECTION_WINDOW_DAYS = 7;

    protected $fillable = [
        'tenant_id', 'site_id', 'organism_type', 'organism_detail',
        'started_at', 'declared_ended_at', 'attack_rate_pct',
        'containment_measures_text', 'reported_to_state_at',
        'status', 'declared_by_user_id', 'notes',
    ];

    protected $casts = [
        'started_at'           => 'datetime',
        'declared_ended_at'    => 'datetime',
        'reported_to_state_at' => 'datetime',
        'attack_rate_pct'      => 'decimal:2',
    ];

    public function tenant(): BelongsTo       { return $this->belongsTo(Tenant::class); }
    public function site(): BelongsTo         { return $this->belongsTo(Site::class); }
    public function declaredBy(): BelongsTo   { return $this->belongsTo(User::class, 'declared_by_user_id'); }
    public function cases(): HasMany          { return $this->hasMany(InfectionCase::class, 'outbreak_id'); }

    public function scopeForTenant($q, int $tenantId) { return $q->where('tenant_id', $tenantId); }
    public function scopeActive($q)                   { return $q->where('status', 'active'); }
}
