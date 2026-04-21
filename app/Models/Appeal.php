<?php

// ─── Appeal ───────────────────────────────────────────────────────────────────
// 42 CFR §460.122 participant appeal of a service denial.
//
// Clocks:
//   type=standard    → internal decision due within 30 days of filing
//   type=expedited   → internal decision due within 72 hours of filing
//
// Continuation of benefits (§460.122): when appealing termination/reduction of
// an ongoing service, the service continues until decision. Enforced by
// AppealService + surfaced on the linked SDR/care plan goal.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Appeal extends Model
{
    use HasFactory;

    protected $table = 'emr_appeals';

    public const TYPE_STANDARD  = 'standard';
    public const TYPE_EXPEDITED = 'expedited';
    public const TYPES = [self::TYPE_STANDARD, self::TYPE_EXPEDITED];

    public const STATUS_RECEIVED                    = 'received';
    public const STATUS_ACKNOWLEDGED                = 'acknowledged';
    public const STATUS_UNDER_REVIEW                = 'under_review';
    public const STATUS_DECIDED_UPHELD              = 'decided_upheld';
    public const STATUS_DECIDED_OVERTURNED          = 'decided_overturned';
    public const STATUS_DECIDED_PARTIALLY_OVERTURNED = 'decided_partially_overturned';
    public const STATUS_WITHDRAWN                   = 'withdrawn';
    public const STATUS_EXTERNAL_REVIEW_REQUESTED   = 'external_review_requested';
    public const STATUS_CLOSED                      = 'closed';

    public const STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_ACKNOWLEDGED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_DECIDED_UPHELD,
        self::STATUS_DECIDED_OVERTURNED,
        self::STATUS_DECIDED_PARTIALLY_OVERTURNED,
        self::STATUS_WITHDRAWN,
        self::STATUS_EXTERNAL_REVIEW_REQUESTED,
        self::STATUS_CLOSED,
    ];

    public const DECIDED_STATUSES = [
        self::STATUS_DECIDED_UPHELD,
        self::STATUS_DECIDED_OVERTURNED,
        self::STATUS_DECIDED_PARTIALLY_OVERTURNED,
    ];

    public const OPEN_STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_ACKNOWLEDGED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_EXTERNAL_REVIEW_REQUESTED,
    ];

    public const FILED_BY_VALUES = ['participant', 'representative', 'staff_on_behalf'];

    public const EXTERNAL_REVIEW_OUTCOMES = [
        'pending', 'upheld', 'overturned', 'partially_overturned', 'withdrawn',
    ];

    // Clock windows per §460.122
    public const STANDARD_DECISION_WINDOW_DAYS  = 30;
    public const EXPEDITED_DECISION_WINDOW_HOURS = 72;

    protected $fillable = [
        'tenant_id',
        'participant_id',
        'service_denial_notice_id',
        'type',
        'status',
        'filed_by',
        'filed_by_name',
        'filing_reason',
        'filed_at',
        'internal_decision_due_at',
        'internal_decision_at',
        'internal_decision_by_user_id',
        'decision_narrative',
        'continuation_of_benefits',
        'external_review_requested_at',
        'external_review_outcome',
        'external_review_outcome_at',
        'external_review_narrative',
        'acknowledgment_pdf_document_id',
        'decision_pdf_document_id',
    ];

    protected $casts = [
        'filed_at'                     => 'datetime',
        'internal_decision_due_at'     => 'datetime',
        'internal_decision_at'         => 'datetime',
        'continuation_of_benefits'     => 'boolean',
        'external_review_requested_at' => 'datetime',
        'external_review_outcome_at'   => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo           { return $this->belongsTo(Tenant::class); }
    public function participant(): BelongsTo      { return $this->belongsTo(Participant::class); }
    public function denialNotice(): BelongsTo     { return $this->belongsTo(ServiceDenialNotice::class, 'service_denial_notice_id'); }
    public function decidedBy(): BelongsTo        { return $this->belongsTo(User::class, 'internal_decision_by_user_id'); }
    public function acknowledgmentPdf(): BelongsTo { return $this->belongsTo(Document::class, 'acknowledgment_pdf_document_id'); }
    public function decisionPdf(): BelongsTo      { return $this->belongsTo(Document::class, 'decision_pdf_document_id'); }
    public function events(): HasMany             { return $this->hasMany(AppealEvent::class)->orderBy('occurred_at'); }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForTenant(Builder $q, int $tenantId): Builder
    {
        return $q->where('tenant_id', $tenantId);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeOverdue(Builder $q): Builder
    {
        return $q->whereIn('status', self::OPEN_STATUSES)
                 ->where('internal_decision_due_at', '<', now());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isDecided(): bool
    {
        return in_array($this->status, self::DECIDED_STATUSES, true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->internal_decision_due_at && $this->internal_decision_due_at->isPast();
    }

    /** Percent of the decision window consumed — used by the UI timer/color. */
    public function windowElapsedPercent(): int
    {
        $total = $this->filed_at->diffInSeconds($this->internal_decision_due_at);
        if ($total <= 0) return 100;
        $used = $this->filed_at->diffInSeconds(min(now(), $this->internal_decision_due_at));
        return (int) max(0, min(100, round($used * 100 / $total)));
    }
}
