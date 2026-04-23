<?php

// ─── CriticalValueAcknowledgment ────────────────────────────────────────────
// Phase B6. One row per flagged out-of-range vital (or lab). Provider must
// ack + document action within deadline_at or the row is escalated by
// CriticalValueEscalationJob.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CriticalValueAcknowledgment extends Model
{
    protected $table = 'emr_critical_value_acknowledgments';

    /** Deadline hours from creation until ack is considered overdue. */
    public const DEADLINE_HOURS_CRITICAL = 2;
    public const DEADLINE_HOURS_WARNING  = 8;

    protected $fillable = [
        'tenant_id', 'participant_id', 'vital_id', 'lab_result_id',
        'field_name', 'value', 'severity', 'direction',
        'deadline_at', 'acknowledged_at', 'acknowledged_by_user_id',
        'action_taken_text', 'escalated_at',
    ];

    protected $casts = [
        'deadline_at'     => 'datetime',
        'acknowledged_at' => 'datetime',
        'escalated_at'    => 'datetime',
        'value'           => 'decimal:2',
    ];

    public function participant(): BelongsTo    { return $this->belongsTo(Participant::class); }
    public function vital(): BelongsTo          { return $this->belongsTo(Vital::class); }
    public function labResult(): BelongsTo      { return $this->belongsTo(LabResult::class); }
    public function acknowledgedBy(): BelongsTo { return $this->belongsTo(User::class, 'acknowledged_by_user_id'); }

    public function scopeForTenant($q, int $t)  { return $q->where('tenant_id', $t); }
    public function scopePending($q)            { return $q->whereNull('acknowledged_at'); }
    public function scopeOverdue($q)            { return $q->whereNull('acknowledged_at')->where('deadline_at', '<', now()); }

    public function isAcknowledged(): bool      { return $this->acknowledged_at !== null; }
    public function isOverdue(): bool           { return ! $this->isAcknowledged() && $this->deadline_at?->isPast(); }
    public function isEscalated(): bool         { return $this->escalated_at !== null; }
}
