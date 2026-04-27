<?php

// ─── RestraintEpisode ────────────────────────────────────────────────────────
// Phase B1 : restraint documentation. One episode per start/stop. Physical,
// chemical, or both. Chemical (or both) requires an ordering provider.
// Monitoring at the declared interval (default 15 min physical / 30 min
// chemical) via RestraintMonitoringObservation children. IDT review required
// within 24h of initiation (42 CFR §460 + CMS PACE Audit Protocol).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestraintEpisode extends Model
{
    protected $table = 'emr_restraint_episodes';

    public const TYPES = ['physical', 'chemical', 'both'];
    public const STATUSES = ['active', 'discontinued', 'expired'];

    public const DEFAULT_MONITORING_INTERVAL_MIN_PHYSICAL = 15;
    public const DEFAULT_MONITORING_INTERVAL_MIN_CHEMICAL = 30;

    /** Minutes since last monitoring observation that triggers a warning alert. */
    public const MONITORING_OVERDUE_MIN = 240; // 4 hours

    /** Hours from initiation without an IDT review that triggers a critical alert. */
    public const IDT_REVIEW_DEADLINE_HOURS = 24;

    protected $fillable = [
        'tenant_id', 'participant_id', 'restraint_type',
        'initiated_at', 'initiated_by_user_id',
        'reason_text', 'alternatives_tried_text',
        'ordering_provider_user_id', 'medication_text',
        'monitoring_interval_min', 'status',
        'discontinued_at', 'discontinued_by_user_id', 'discontinuation_reason',
        'idt_review_date', 'idt_review_user_id', 'outcome_text',
    ];

    protected $casts = [
        'initiated_at'            => 'datetime',
        'discontinued_at'         => 'datetime',
        'idt_review_date'         => 'date',
        'monitoring_interval_min' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function tenant(): BelongsTo       { return $this->belongsTo(Tenant::class); }
    public function participant(): BelongsTo  { return $this->belongsTo(Participant::class); }
    public function initiatedBy(): BelongsTo  { return $this->belongsTo(User::class, 'initiated_by_user_id'); }
    public function orderedBy(): BelongsTo    { return $this->belongsTo(User::class, 'ordering_provider_user_id'); }
    public function discontinuedBy(): BelongsTo { return $this->belongsTo(User::class, 'discontinued_by_user_id'); }
    public function idtReviewer(): BelongsTo  { return $this->belongsTo(User::class, 'idt_review_user_id'); }
    public function observations(): HasMany   { return $this->hasMany(RestraintMonitoringObservation::class); }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeForTenant($q, int $tenantId)   { return $q->where('tenant_id', $tenantId); }
    public function scopeActive($q)                     { return $q->where('status', 'active'); }

    // ── Business logic ───────────────────────────────────────────────────────

    public function isActive(): bool { return $this->status === 'active'; }

    public function isChemical(): bool
    {
        return in_array($this->restraint_type, ['chemical', 'both'], true);
    }

    public function lastObservationAt(): ?\Illuminate\Support\Carbon
    {
        return $this->observations()->latest('observed_at')->value('observed_at');
    }

    /** Minutes since the last observation (or since initiation if none). */
    public function minutesSinceLastObservation(): int
    {
        $last = $this->lastObservationAt() ?? $this->initiated_at;
        return (int) $last->diffInMinutes(now());
    }

    public function monitoringOverdue(): bool
    {
        return $this->isActive()
            && $this->minutesSinceLastObservation() > self::MONITORING_OVERDUE_MIN;
    }

    public function idtReviewOverdue(): bool
    {
        if ($this->idt_review_date !== null) return false;
        $deadline = $this->initiated_at->copy()->addHours(self::IDT_REVIEW_DEADLINE_HOURS);
        return $deadline->isPast();
    }
}
