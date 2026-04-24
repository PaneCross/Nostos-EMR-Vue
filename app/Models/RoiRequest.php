<?php

// ─── RoiRequest ──────────────────────────────────────────────────────────────
// Phase B8b. Release of Information (medical records disclosure) request.
// HIPAA §164.524 → must respond within 30 days of requested_at.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoiRequest extends Model
{
    protected $table = 'emr_roi_requests';

    public const REQUESTOR_TYPES = ['self', 'legal_rep', 'provider', 'attorney', 'insurer', 'other'];
    public const STATUSES        = ['pending', 'in_progress', 'fulfilled', 'denied', 'withdrawn'];
    public const OPEN_STATUSES   = ['pending', 'in_progress'];

    /** HIPAA §164.524(b)(2) — response window. */
    public const RESPONSE_DEADLINE_DAYS = 30;

    protected $fillable = [
        'tenant_id', 'participant_id',
        'requestor_type', 'requestor_name', 'requestor_contact',
        'records_requested_scope',
        'requested_at', 'due_by', 'status',
        'fulfilled_at', 'fulfilled_by_user_id', 'denial_reason', 'notes',
    ];

    protected $casts = [
        'requested_at'  => 'datetime',
        'due_by'        => 'datetime',
        'fulfilled_at'  => 'datetime',
    ];

    public function tenant(): BelongsTo       { return $this->belongsTo(Tenant::class); }
    public function participant(): BelongsTo  { return $this->belongsTo(Participant::class); }
    public function fulfilledBy(): BelongsTo  { return $this->belongsTo(User::class, 'fulfilled_by_user_id'); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
    public function scopeOpen($q)              { return $q->whereIn('status', self::OPEN_STATUSES); }

    public function isOpen(): bool     { return in_array($this->status, self::OPEN_STATUSES, true); }
    public function isOverdue(): bool  { return $this->isOpen() && $this->due_by?->isPast(); }
    public function daysUntilDue(): ?int
    {
        return $this->due_by ? (int) now()->diffInDays($this->due_by, false) : null;
    }
}
