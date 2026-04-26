<?php

// ─── AmendmentRequest — Phase P3 ────────────────────────────────────────────
// HIPAA §164.526 Right to Amend.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmendmentRequest extends Model
{
    protected $table = 'emr_amendment_requests';

    public const STATUSES        = ['pending', 'under_review', 'accepted', 'denied', 'withdrawn'];
    public const OPEN_STATUSES   = ['pending', 'under_review'];
    public const RESPONSE_DAYS   = 60;
    public const EXTENSION_DAYS  = 30;

    protected $fillable = [
        'tenant_id', 'participant_id', 'requested_by_portal_user_id',
        'target_record_type', 'target_record_id', 'target_field_or_section',
        'requested_change', 'justification',
        'status', 'reviewer_user_id', 'reviewer_decision_at', 'decision_rationale',
        'deadline_at', 'patient_disagreement_statement',
        // Phase X3 — Audit-12 H3: optimistic-lock counter
        'revision', 'last_edited_at', 'last_edited_by_user_id',
    ];

    protected $casts = [
        'reviewer_decision_at' => 'datetime',
        'deadline_at'          => 'datetime',
        'revision'             => 'integer',
        'last_edited_at'       => 'datetime',
    ];

    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }
    public function reviewer(): BelongsTo    { return $this->belongsTo(User::class, 'reviewer_user_id'); }
    public function requestedBy(): BelongsTo { return $this->belongsTo(ParticipantPortalUser::class, 'requested_by_portal_user_id'); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
    public function scopeOpen($q)              { return $q->whereIn('status', self::OPEN_STATUSES); }
    public function scopeOverdue($q)           { return $q->whereIn('status', self::OPEN_STATUSES)->where('deadline_at', '<', now()); }

    public function isOpen(): bool { return in_array($this->status, self::OPEN_STATUSES, true); }
    public function isOverdue(): bool { return $this->isOpen() && $this->deadline_at?->isPast(); }
    public function daysRemaining(): ?int { return $this->deadline_at ? (int) now()->diffInDays($this->deadline_at, false) : null; }
}
