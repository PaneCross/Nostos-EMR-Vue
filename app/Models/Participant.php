<?php

// ─── Participant Model ─────────────────────────────────────────────────────────
// The central record for every PACE participant (patient) in the system.
//
// Multi-tenancy: every participant belongs to a tenant (PACE organization) and a
// site (physical PACE center). All queries must be scoped by tenant_id : use the
// forTenant() scope or the CheckDepartmentAccess middleware, never query globally.
//
// MRN: auto-generated on creation by MrnService using the site's mrn_prefix +
// a zero-padded sequence (e.g., "SRP-000042"). Once set it is never changed.
//
// Enrollment lifecycle (42 CFR §460.150-§460.164):
//   referred → intake → pending → enrolled → disenrolled
//   Death is a disenrollment REASON, not a top-level status (§460.160(b)).
//   disenrollment_type rolls up reasons as: voluntary | involuntary | death.
//   Only 'enrolled' participants appear in the active census.
//   Soft deletes preserve the record for audit purposes; use is_active=false
//   to hide a participant from views without deleting the row.
//
// Clinical data (Phase 3+) is stored in child models (ClinicalNote, Vital, etc.)
// and loaded lazily via their own endpoints. Only small datasets (allergies,
// problems) are pre-loaded in ParticipantController::show() to keep page payload small.
//
// NF eligibility: nursing_facility_eligible + nf_certification_date track CMS
// nursing-home level-of-care certification (required for PACE enrollment).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use App\Services\MrnService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Participant extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_participants';

    /** Phase C3 : Hospice workflow state values. */
    public const HOSPICE_STATUSES = ['none', 'referred', 'enrolled', 'graduated', 'deceased'];

    /** Days an enrolled hospice participant can go without IDT review before alerting. */
    public const HOSPICE_IDT_REVIEW_DAYS = 180;

    protected $fillable = [
        'tenant_id', 'site_id', 'mrn', 'barcode_value', 'primary_care_user_id',
        'first_name', 'last_name', 'preferred_name', 'dob', 'gender', 'pronouns',
        'ssn_last_four', 'medicare_id', 'medicare_a_start_date', 'medicare_b_start_date',
        'medicaid_id', 'county_fips_code', 'pace_contract_id', 'h_number',
        'primary_language', 'interpreter_needed', 'interpreter_language',
        'enrollment_status', 'enrollment_date', 'disenrollment_date', 'disenrollment_reason', 'disenrollment_type',
        // Phase C3 : hospice workflow state
        'hospice_status', 'hospice_started_at', 'hospice_last_idt_review_at',
        'hospice_provider_text', 'hospice_diagnosis_text',
        'nursing_facility_eligible', 'nf_certification_date',
        'nf_certification_expires_at', 'nf_recert_waived', 'nf_recert_waived_reason',
        'photo_path', 'is_active', 'created_by_user_id',
        // Phase 11B: advance directive structured fields
        'advance_directive_status', 'advance_directive_type',
        'advance_directive_reviewed_at', 'advance_directive_reviewed_by_user_id',
        // W4-3: demographics expansion (race/ethnicity, marital, legal rep, SDOH)
        'race', 'ethnicity', 'race_detail',
        'marital_status', 'legal_representative_type', 'legal_representative_contact_id',
        'religion', 'veteran_status', 'education_level',
    ];

    protected $casts = [
        'dob'                           => 'date',
        'enrollment_date'               => 'date',
        'disenrollment_date'            => 'date',
        'nf_certification_date'         => 'date',
        'nf_certification_expires_at'   => 'date',
        'nf_recert_waived'              => 'boolean',
        // Phase C3 : hospice timestamps
        'hospice_started_at'            => 'datetime',
        'hospice_last_idt_review_at'    => 'datetime',
        // W4-9: HPMS enrollment file fields (GAP-14)
        'medicare_a_start_date'         => 'date',
        'medicare_b_start_date'         => 'date',
        'interpreter_needed'            => 'boolean',
        'nursing_facility_eligible'     => 'boolean',
        'is_active'                     => 'boolean',
        'advance_directive_reviewed_at' => 'date',
        // W4-2 HIPAA §164.312(a)(2)(iv): PHI identifier fields encrypted at rest using APP_KEY.
        // Laravel's 'encrypted' cast uses AES-256-CBC via Crypt::encryptString() on write
        // and Crypt::decryptString() on read : transparent to all Eloquent callers.
        // IMPORTANT: Rotating APP_KEY requires re-seeding (decrypt fails with wrong key).
        'ssn_last_four'                 => 'encrypted',
        'medicare_id'                   => 'encrypted',
        'medicaid_id'                   => 'encrypted',
        // Recurring day-center schedule: JSONB column holding weekday codes like
        // ['mon','wed','fri']. Without this cast, the value is returned as a JSON
        // string and every in_array()/foreach consumer (DayCenterController,
        // attendance seeder, test fixtures) silently skips the participant.
        'day_center_days'               => 'array',
    ];

    // ─── Auto-generate MRN on creation ────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Participant $participant) {
            if (empty($participant->mrn)) {
                $site = Site::findOrFail($participant->site_id);
                $participant->mrn = app(MrnService::class)->generate($site);
            }
            // Phase B4 : auto-generate BCMA barcode. Format: PT-<tenant>-<mrn>.
            if (empty($participant->barcode_value) && ! empty($participant->mrn)) {
                $participant->barcode_value = "PT-{$participant->tenant_id}-{$participant->mrn}";
            }
        });
    }

    // ─── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(ParticipantAddress::class, 'participant_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ParticipantContact::class, 'participant_id');
    }

    public function flags(): HasMany
    {
        return $this->hasMany(ParticipantFlag::class, 'participant_id');
    }

    public function activeFlags(): HasMany
    {
        return $this->hasMany(ParticipantFlag::class, 'participant_id')
            ->where('is_active', true)
            ->whereNull('deleted_at');
    }

    public function insuranceCoverages(): HasMany
    {
        return $this->hasMany(InsuranceCoverage::class, 'participant_id');
    }

    // ─── Phase 3: Clinical relationships ──────────────────────────────────────

    public function clinicalNotes(): HasMany
    {
        return $this->hasMany(ClinicalNote::class, 'participant_id');
    }

    public function vitals(): HasMany
    {
        return $this->hasMany(Vital::class, 'participant_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'participant_id');
    }

    public function problems(): HasMany
    {
        return $this->hasMany(Problem::class, 'participant_id');
    }

    public function allergies(): HasMany
    {
        return $this->hasMany(Allergy::class, 'participant_id');
    }

    public function adlRecords(): HasMany
    {
        return $this->hasMany(AdlRecord::class, 'participant_id');
    }

    public function adlThresholds(): HasMany
    {
        return $this->hasMany(AdlThreshold::class, 'participant_id');
    }

    /** Active allergies with life-threatening severity : drives the red profile banner. */
    public function activeLifeThreateningAllergies(): HasMany
    {
        return $this->hasMany(Allergy::class, 'participant_id')
            ->where('is_active', true)
            ->where('severity', Allergy::LIFE_THREATENING);
    }

    // ─── Phase 5A: Scheduling relationships ───────────────────────────────────

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'participant_id');
    }

    // ─── Phase 5C: Medications relationships ──────────────────────────────────

    public function medications(): HasMany
    {
        return $this->hasMany(Medication::class, 'participant_id');
    }

    public function emarRecords(): HasMany
    {
        return $this->hasMany(EmarRecord::class, 'participant_id');
    }

    public function medReconciliations(): HasMany
    {
        return $this->hasMany(MedReconciliation::class, 'participant_id');
    }

    public function drugInteractionAlerts(): HasMany
    {
        return $this->hasMany(DrugInteractionAlert::class, 'participant_id');
    }

    /** Phase 14.1: care plans relationship for PDF generation + participant tabs. */
    public function carePlans(): HasMany
    {
        return $this->hasMany(CarePlan::class, 'participant_id');
    }

    /** Phase B1: physical + chemical restraint episodes (42 CFR §460 audit). */
    public function restraintEpisodes(): HasMany
    {
        return $this->hasMany(RestraintEpisode::class, 'participant_id');
    }

    public function immunizations(): HasMany
    {
        return $this->hasMany(Immunization::class, 'participant_id');
    }

    public function socialDeterminants(): HasMany
    {
        return $this->hasMany(SocialDeterminant::class, 'participant_id');
    }

    public function procedures(): HasMany
    {
        return $this->hasMany(Procedure::class, 'participant_id');
    }

    public function ehiExports(): HasMany
    {
        return $this->hasMany(EhiExport::class, 'participant_id');
    }

    /** Unacknowledged drug interaction alerts : shown in header/banner. */
    public function unacknowledgedInteractionAlerts(): HasMany
    {
        return $this->hasMany(DrugInteractionAlert::class, 'participant_id')
            ->where('is_acknowledged', false);
    }

    // ─── Phase 10A / W3-6: Site transfer relationships ────────────────────────

    public function siteTransfers(): HasMany
    {
        return $this->hasMany(ParticipantSiteTransfer::class, 'participant_id');
    }

    // ─── W4-5: IDT review frequency relationships ─────────────────────────────

    /**
     * All IDT participant reviews for this participant.
     * Reviews are linked through IdtMeeting (meeting_id FK) : see IdtParticipantReview model.
     * Note: the model uses `meeting_id` (not `idt_meeting_id`) and has no `tenant_id`.
     */
    public function idtParticipantReviews(): HasMany
    {
        return $this->hasMany(IdtParticipantReview::class, 'participant_id');
    }

    // ─── W4-5: Disenrollment record relationship ──────────────────────────────

    public function disenrollmentRecords(): HasMany
    {
        return $this->hasMany(DisenrollmentRecord::class, 'participant_id');
    }

    /**
     * Returns true if this participant has ever completed a site transfer.
     * Used to decide whether site-source labels should appear on clinical data.
     */
    public function hasMultipleSites(): bool
    {
        return $this->siteTransfers()->where('status', 'completed')->exists();
    }

    // ─── W4-5: IDT review frequency helpers ──────────────────────────────────

    /**
     * Returns the most recent IDT review date for this participant, or null if
     * no reviews have been recorded. Used by idtReviewOverdue() and the
     * participant header badge.
     * 42 CFR §460.104(c): reassessment at least every 6 months.
     */
    public function lastIdtReviewedAt(): ?\Illuminate\Support\Carbon
    {
        // Phase Y7 (Audit-13 perf): if the controller pre-loaded the max
        // reviewed_at via withMax(), use it instead of running a fresh query.
        // Cuts the participant directory listing from 61 → 10 queries at 200
        // enrolled. Falls back to live query for callers that don't pre-load.
        if (array_key_exists('last_idt_reviewed_at_raw', $this->getAttributes())) {
            $raw = $this->getAttribute('last_idt_reviewed_at_raw');
            return $raw ? \Illuminate\Support\Carbon::parse($raw) : null;
        }

        $latest = $this->idtParticipantReviews()
            ->whereNotNull('reviewed_at')
            ->orderByDesc('reviewed_at')
            ->value('reviewed_at');

        return $latest ? \Illuminate\Support\Carbon::parse($latest) : null;
    }

    /**
     * True when this participant is overdue for an IDT reassessment.
     * "Overdue" = last review was more than 180 days ago (or no review exists).
     * 42 CFR §460.104(c) requires reassessment every 6 months.
     */
    public function idtReviewOverdue(): bool
    {
        // Only enrolled participants can be overdue
        if (! $this->isEnrolled()) {
            return false;
        }

        $lastReview = $this->lastIdtReviewedAt();

        if (is_null($lastReview)) {
            // No review on record : overdue if enrolled more than 180 days
            return $this->enrollment_date !== null
                && $this->enrollment_date->diffInDays(now()) > 180;
        }

        return $lastReview->diffInDays(now()) > 180;
    }

    /**
     * Days until NF-LOC recert is due. Negative = overdue.
     * Returns null if no cert date is recorded or recert is waived.
     * 42 CFR §460.160(b)(2).
     */
    public function nfLocRecertDaysRemaining(): ?int
    {
        if ($this->nf_recert_waived) return null;
        if (! $this->nf_certification_expires_at) return null;
        return (int) floor(now()->startOfDay()->diffInDays($this->nf_certification_expires_at, false));
    }

    /** True when NF-LOC recert is overdue (expires_at in the past, not waived, enrolled). */
    public function nfLocRecertOverdue(): bool
    {
        if (! $this->isEnrolled() || $this->nf_recert_waived) return false;
        return $this->nf_certification_expires_at
            && $this->nf_certification_expires_at->isPast();
    }

    // ─── Computed helpers ──────────────────────────────────────────────────────

    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function displayName(): string
    {
        $name = $this->fullName();
        if ($this->preferred_name) {
            $name .= " \"{$this->preferred_name}\"";
        }
        return $name;
    }

    public function age(): int
    {
        return $this->dob->age;
    }

    /**
     * True when the participant was disenrolled with reason=death.
     * Per 42 CFR §460.160(b), death is a disenrollment reason, not a top-level status
     * (see feedback_pace_disenrollment_taxonomy.md).
     */
    public function isDeceased(): bool
    {
        return $this->enrollment_status === 'disenrolled'
            && ($this->disenrollment_type === 'death' || $this->disenrollment_reason === 'death');
    }

    /** Whether participant has a DNR specifically (not POLST/living will). */
    public function hasDnr(): bool
    {
        return $this->advance_directive_status === 'has_directive'
            && $this->advance_directive_type === 'dnr';
    }

    /** Advance directive status label for display. */
    public function advanceDirectiveLabel(): ?string
    {
        return match ($this->advance_directive_status) {
            'has_directive'               => match ($this->advance_directive_type) {
                'dnr'            => 'DNR on File',
                'polst'          => 'POLST on File',
                'living_will'    => 'Living Will on File',
                'healthcare_proxy'=> 'Healthcare Proxy on File',
                'combined'       => 'Full Directive on File',
                default          => 'Directive on File',
            },
            'declined_directive'          => 'Declined Directive',
            'incapacitated_no_directive'  => 'Incapacitated : No Directive',
            'unknown'                     => 'Directive Status Unknown',
            default                       => null,
        };
    }

    public function isEnrolled(): bool
    {
        return $this->enrollment_status === 'enrolled';
    }

    // ─── Scopes ────────────────────────────────────────────────────────────────

    /** Restrict results to the given tenant. Always apply before returning participant lists. */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Full-text name/MRN search across the participant directory.
     * Searches first_name, last_name, MRN, and concatenated full name.
     * Uses PostgreSQL case-insensitive ILIKE : not portable to MySQL.
     * The CONCAT clause catches "John Doe" style searches where neither token
     * matches a single column individually.
     */
    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('first_name', 'ilike', "%{$term}%")
              ->orWhere('last_name', 'ilike', "%{$term}%")
              ->orWhere('mrn', 'ilike', "%{$term}%")
              ->orWhereRaw("CONCAT(first_name, ' ', last_name) ILIKE ?", ["%{$term}%"]);
        });
    }

    /**
     * Exact date-of-birth lookup used by front desk to locate participants
     * when a name search returns multiple results.
     * Accepts ISO date string (Y-m-d).
     */
    public function scopeSearchByDob($query, string $dob)
    {
        return $query->whereDate('dob', $dob);
    }
}
