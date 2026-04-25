<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BreachIncident extends Model
{
    protected $table = 'emr_breach_incidents';

    public const TYPES    = ['lost_device', 'email_misdirect', 'unauthorized_access', 'hacking', 'paper_disposal', 'improper_disclosure', 'other'];
    public const STATUSES = ['open', 'individuals_notified', 'hhs_notified', 'closed'];

    /** §164.408 — 500+ affected: HHS within 60 calendar days of discovery. */
    public const LARGE_BREACH_THRESHOLD = 500;
    public const LARGE_BREACH_DEADLINE_DAYS = 60;

    protected $fillable = [
        'tenant_id', 'discovered_at', 'occurred_at', 'affected_count',
        'breach_type', 'description', 'root_cause', 'mitigation_taken', 'state',
        'individual_notification_sent_at', 'hhs_notified_at', 'media_notified_at',
        'hhs_deadline_at', 'status', 'logged_by_user_id',
    ];

    protected $casts = [
        'discovered_at'                   => 'datetime',
        'occurred_at'                     => 'datetime',
        'individual_notification_sent_at' => 'datetime',
        'hhs_notified_at'                 => 'datetime',
        'media_notified_at'               => 'datetime',
        'hhs_deadline_at'                 => 'datetime',
    ];

    public function loggedBy(): BelongsTo { return $this->belongsTo(User::class, 'logged_by_user_id'); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
    public function scopeOpen($q)              { return $q->whereNotIn('status', ['closed']); }

    /**
     * §164.408 deadline: 500+ affected → 60 days from discovery.
     * <500 affected → by March 1 of the year following discovery.
     */
    public static function computeHhsDeadline(int $affected, Carbon $discovered): Carbon
    {
        if ($affected >= self::LARGE_BREACH_THRESHOLD) {
            return $discovered->copy()->addDays(self::LARGE_BREACH_DEADLINE_DAYS);
        }
        return Carbon::create($discovered->year + 1, 3, 1, 0, 0, 0, $discovered->timezone);
    }

    public function isLargeBreach(): bool { return $this->affected_count >= self::LARGE_BREACH_THRESHOLD; }
    public function isOverdue(): bool     { return $this->status !== 'hhs_notified' && $this->status !== 'closed' && $this->hhs_deadline_at?->isPast(); }
    public function daysUntilDeadline(): ?int { return $this->hhs_deadline_at ? (int) now()->diffInDays($this->hhs_deadline_at, false) : null; }
}
