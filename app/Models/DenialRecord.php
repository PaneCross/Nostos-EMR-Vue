<?php

// ─── DenialRecord ─────────────────────────────────────────────────────────────
//
// Tracks a denied claim through the denial management workflow.
// Created automatically by Process835RemittanceJob when a RemittanceClaim
// has claim_status = 'denied'.
//
// Denial lifecycle:
//   open → appealing → won | lost | written_off
//
// CMS Medicare appeal deadline: 120 days from denial date (42 CFR §405.942).
// The appeal_deadline is auto-set as denial_date + APPEAL_DEADLINE_DAYS.
//
// Denial categories are inferred from the primary CARC reason code
// using the category map in Remittance835ParserService::categorizeDenial().

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DenialRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_denial_records';

    // ── Business rule constants ────────────────────────────────────────────────

    /**
     * CMS Medicare appeal deadline per 42 CFR §405.942.
     * PACE-specific: 120 days from denial date for redetermination.
     */
    public const APPEAL_DEADLINE_DAYS = 120;

    // ── Status constants ───────────────────────────────────────────────────────

    public const STATUSES = ['open', 'appealing', 'won', 'lost', 'written_off'];

    public const STATUS_LABELS = [
        'open'        => 'Open',
        'appealing'   => 'Appeal in Progress',
        'won'         => 'Appeal Won',
        'lost'        => 'Appeal Lost',
        'written_off' => 'Written Off',
    ];

    /** Terminal statuses : no further workflow actions possible. */
    public const TERMINAL_STATUSES = ['won', 'lost', 'written_off'];

    // ── Denial category constants ──────────────────────────────────────────────

    public const CATEGORIES = [
        'authorization',
        'coding_error',
        'timely_filing',
        'duplicate',
        'medical_necessity',
        'coordination_of_benefits',
        'other',
    ];

    public const CATEGORY_LABELS = [
        'authorization'             => 'Authorization / Pre-Auth',
        'coding_error'              => 'Coding Error',
        'timely_filing'             => 'Timely Filing',
        'duplicate'                 => 'Duplicate Claim',
        'medical_necessity'         => 'Medical Necessity',
        'coordination_of_benefits'  => 'Coordination of Benefits',
        'other'                     => 'Other',
    ];

    // ── Fillable ───────────────────────────────────────────────────────────────

    protected $fillable = [
        'remittance_claim_id',
        'tenant_id',
        'encounter_log_id',
        'denial_category',
        'status',
        'denied_amount',
        'primary_reason_code',
        'denial_reason',
        'denial_date',
        'appeal_deadline',
        'appeal_submitted_date',
        'appeal_notes',
        'resolution_date',
        'resolution_notes',
        'written_off_by_user_id',
        'written_off_at',
        'assigned_to_user_id',
    ];

    protected $casts = [
        'denied_amount'          => 'decimal:2',
        'denial_date'            => 'date',
        'appeal_deadline'        => 'date',
        'appeal_submitted_date'  => 'date',
        'resolution_date'        => 'date',
        'written_off_at'         => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function remittanceClaim(): BelongsTo
    {
        return $this->belongsTo(RemittanceClaim::class, 'remittance_claim_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function encounterLog(): BelongsTo
    {
        return $this->belongsTo(EncounterLog::class, 'encounter_log_id');
    }

    public function writtenOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'written_off_by_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    // ── Query Scopes ───────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Open denials that can still be worked. */
    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', self::TERMINAL_STATUSES);
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeWithCategory($query, string $category)
    {
        return $query->where('denial_category', $category);
    }

    /**
     * Denials past their CMS appeal deadline with no resolution.
     * These represent revenue at risk with no further recourse.
     */
    public function scopeOverdueForAppeal($query)
    {
        return $query->whereIn('status', ['open', 'appealing'])
            ->where('appeal_deadline', '<', now()->toDateString())
            ->whereNull('resolution_date');
    }

    /** Denials with appeals due within the next $days days. */
    public function scopeAppealDueSoon($query, int $days = 30)
    {
        return $query->whereIn('status', ['open'])
            ->whereBetween('appeal_deadline', [
                now()->toDateString(),
                now()->addDays($days)->toDateString(),
            ]);
    }

    // ── Business logic ─────────────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isAppealing(): bool
    {
        return $this->status === 'appealing';
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isOverdueForAppeal(): bool
    {
        if ($this->isTerminal()) {
            return false;
        }

        return $this->appeal_deadline !== null
            && $this->appeal_deadline->isPast();
    }

    /** Days remaining before the CMS appeal deadline. Negative = overdue. */
    public function daysUntilAppealDeadline(): int
    {
        if ($this->appeal_deadline === null) {
            return self::APPEAL_DEADLINE_DAYS;
        }

        // Carbon 3: use now()->diffInDays($futureDate) for correct positive value
        $days = (int) now()->diffInDays($this->appeal_deadline, false);
        return $days;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst($this->status);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->denial_category] ?? ucfirst($this->denial_category ?? 'Unknown');
    }

    // ── API Serialization ──────────────────────────────────────────────────────

    public function toApiArray(): array
    {
        return [
            'id'                     => $this->id,
            'remittance_claim_id'    => $this->remittance_claim_id,
            'encounter_log_id'       => $this->encounter_log_id,
            'denial_category'        => $this->denial_category,
            'denial_category_label'  => $this->categoryLabel(),
            'status'                 => $this->status,
            'status_label'           => $this->statusLabel(),
            'denied_amount'          => (float) $this->denied_amount,
            'primary_reason_code'    => $this->primary_reason_code,
            'denial_reason'          => $this->denial_reason,
            'denial_date'            => $this->denial_date?->toDateString(),
            'appeal_deadline'        => $this->appeal_deadline?->toDateString(),
            'days_until_deadline'    => $this->daysUntilAppealDeadline(),
            'is_overdue_for_appeal'  => $this->isOverdueForAppeal(),
            'appeal_submitted_date'  => $this->appeal_submitted_date?->toDateString(),
            'appeal_notes'           => $this->appeal_notes,
            'resolution_date'        => $this->resolution_date?->toDateString(),
            'resolution_notes'       => $this->resolution_notes,
            'assigned_to_user_id'    => $this->assigned_to_user_id,
            'created_at'             => $this->created_at?->toIso8601String(),
            'updated_at'             => $this->updated_at?->toIso8601String(),
        ];
    }
}
