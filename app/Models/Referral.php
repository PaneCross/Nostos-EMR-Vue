<?php

// ─── Referral Model ────────────────────────────────────────────────────────────
// Represents a potential enrollee (42 CFR §460.154) from initial referral through
// enrollment. Once enrolled, a linked Participant record is created and the
// referral transitions to the terminal `enrolled` state.
//
// CMS enrollment state machine (enforced by EnrollmentService, not the model):
//   new → intake_scheduled → intake_in_progress → intake_complete
//     → eligibility_pending → pending_enrollment → enrolled (terminal)
//   OR: any non-terminal state → declined / withdrawn (terminal)
//
// participant_id is NULL until intake_complete, when 'Create Participant Record'
// button links or creates an emr_participants row.
//
// Pipeline display: the 7 active statuses (all except declined/withdrawn)
// form the Kanban columns on the Enrollment Dashboard.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Referral extends Model
{
    use HasFactory;

    protected $table = 'emr_referrals';

    // ── Constants ─────────────────────────────────────────────────────────────

    /** All possible referral_source values (how the referral reached PACE). */
    public const SOURCES = [
        'hospital',
        'physician',
        'family',
        'community',
        'self',
        'other',
    ];

    public const SOURCE_LABELS = [
        'hospital'   => 'Hospital / Discharge',
        'physician'  => 'Physician Referral',
        'family'     => 'Family / Caregiver',
        'community'  => 'Community Organization',
        'self'       => 'Self-Referred',
        'other'      => 'Other',
    ];

    /** Ordered workflow statuses (the Kanban pipeline columns). */
    public const STATUSES = [
        'new',
        'intake_scheduled',
        'intake_in_progress',
        'intake_complete',
        'eligibility_pending',
        'pending_enrollment',
        'enrolled',
        'declined',
        'withdrawn',
    ];

    /** Statuses that represent terminal (non-modifiable) states. */
    public const TERMINAL_STATUSES = ['enrolled', 'declined', 'withdrawn'];

    /** Human-readable labels for pipeline column headers. */
    public const STATUS_LABELS = [
        'new'                => 'New',
        'intake_scheduled'   => 'Intake Scheduled',
        'intake_in_progress' => 'Intake In Progress',
        'intake_complete'    => 'Intake Complete',
        'eligibility_pending'=> 'Eligibility Pending',
        'pending_enrollment' => 'Pending Enrollment',
        'enrolled'           => 'Enrolled',
        'declined'           => 'Declined',
        'withdrawn'          => 'Withdrawn',
    ];

    /** Pipeline kanban columns (excludes terminal exit states). */
    public const PIPELINE_STATUSES = [
        'new',
        'intake_scheduled',
        'intake_in_progress',
        'intake_complete',
        'eligibility_pending',
        'pending_enrollment',
        'enrolled',
    ];

    // ── Fillable + Casts ──────────────────────────────────────────────────────

    protected $fillable = [
        'tenant_id',
        'site_id',
        'referred_by_name',
        'referred_by_org',
        'prospective_first_name',
        'prospective_last_name',
        'prospective_dob',
        'referral_date',
        'referral_source',
        'participant_id',
        'assigned_to_user_id',
        'status',
        'decline_reason',
        'withdrawn_reason',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'referral_date'    => 'date',
        'prospective_dob'  => 'date',
    ];

    /**
     * Display name of the potential enrollee : falls back when not yet set.
     *
     * NPA/CMS terminology note: "potential enrollee" is the 42 CFR §460.154 term
     * for a pre-enrollment individual. Internal DB columns use the shorter
     * `prospective_*` prefix, but user-facing strings must use "Potential
     * Enrollee" or "Participant" (the latter ONLY after enrollment).
     */
    public function potentialEnrolleeName(): string
    {
        $full = trim(($this->prospective_first_name ?? '') . ' ' . ($this->prospective_last_name ?? ''));
        if ($full !== '') return $full;
        if ($this->participant) return $this->participant->first_name . ' ' . $this->participant->last_name;
        return 'Potential enrollee (name pending)';
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** The PACE participant this referral led to (null until intake_complete). */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /** Enrollment team member managing this referral. */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** Staff member who created the referral record. */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Chronological thread of notes attached to this referral (newest first).
     * NOTE: the relationship is named `referralNotes` : NOT `notes` : because
     * `emr_referrals` has an existing `notes` TEXT column for the initial
     * referral narrative, and Eloquent resolves attributes before relationship
     * methods. Calling `$referral->notes` returns the string; call
     * `$referral->referralNotes` for the thread collection.
     */
    public function referralNotes(): HasMany
    {
        return $this->hasMany(ReferralNote::class)->latest('created_at');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /** True once the referral has reached a terminal state (no further transitions allowed). */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /** True if the referral resulted in successful enrollment. */
    public function isEnrolled(): bool
    {
        return $this->status === 'enrolled';
    }

    /** Human-readable label for the current status. */
    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Human-readable label for the referral source. */
    public function sourceLabel(): string
    {
        return self::SOURCE_LABELS[$this->referral_source] ?? $this->referral_source ?? ':';
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Filter by tenant. */
    public function scopeForTenant(\Illuminate\Database\Eloquent\Builder $query, int $tenantId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Active pipeline referrals (excludes declined/withdrawn). */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNotIn('status', ['declined', 'withdrawn']);
    }

    /** Referrals currently unassigned. */
    public function scopeUnassigned(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNull('assigned_to_user_id');
    }
}
