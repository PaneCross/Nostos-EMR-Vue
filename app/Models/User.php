<?php

// ─── User ─────────────────────────────────────────────────────────────────────
// Staff account. Represents a clinician or administrator who logs in to the
// EMR. (Participants — the elderly people receiving care — are NOT users;
// they live in the Participant model. Optional participant portal logins are
// a separate, narrower auth surface.)
//
// Each user belongs to one Tenant and one Site, with a `department` (drives
// RBAC visibility) and a `role` (admin / member / super_admin). Login is
// passwordless: OTP (One-Time Password) email codes or Google/Yahoo OAuth.
// `designations` is an array of sub-roles (medical_director, compliance_officer,
// nursing_director, ...) used to fan out alerts to the right accountable
// person rather than the whole department.
//
// Notable rules:
//  - 42 CFR §460.91 — RBAC is mandatory; `department` + `role` are the keys.
//  - Account lockout after 5 failed attempts (30 min) — HIPAA §164.308 safeguard.
//  - `is_super_admin` flips during impersonation: the IMPERSONATED user never
//    appears as super-admin to the UI, only the real authenticated user does.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'shared_users';

    // ── Designations ──────────────────────────────────────────────────────────

    /**
     * Sub-role designations — identify specific functional accountability roles
     * within a department. Used for targeted alerting and workflow routing.
     *
     * Unlike departments (which control access), designations control WHO gets
     * notified for specific events. A user may hold multiple designations.
     * Designations are assigned by IT Admin.
     *
     * Example use cases:
     *   medical_director    → notified on SDR critical escalations, clinical grievances
     *   compliance_officer  → notified on grievance escalations, CMS incidents
     *   nursing_director    → notified on nursing-related QA alerts
     *   pharmacy_director   → notified on critical drug interaction alerts
     */
    public const DESIGNATIONS = [
        'medical_director',
        'program_director',
        'compliance_officer',
        'nursing_director',
        'pharmacy_director',
        'social_work_supervisor',
    ];

    public const DESIGNATION_LABELS = [
        'medical_director'       => 'Medical Director',
        'program_director'       => 'Program Director',
        'compliance_officer'     => 'Compliance Officer',
        'nursing_director'       => 'Nursing Director',
        'pharmacy_director'      => 'Pharmacy Director',
        'social_work_supervisor' => 'Social Work Supervisor',
    ];

    /**
     * Designation behavior detail — what each designation actually triggers in
     * the codebase today. Surfaced in the IT Admin → Users detail modal so
     * administrators making assignments know exactly what notifications +
     * approval workflows the user will pick up.
     *
     * Honest split:
     *   permissions   — special abilities the designation grants (approval gates).
     *   notifications — events that route alerts to holders of this designation.
     *   reserved      — feature areas the designation is RESERVED for but not
     *                   yet wired in code. Listed so admins know intent vs.
     *                   today's behavior. Wire up as those workflows are built.
     *
     * Source of truth: any new alert routing or permission gate that uses a
     * designation should also update its entry here so the UI stays accurate.
     */
    public const DESIGNATION_DETAILS = [
        'medical_director' => [
            'label'   => 'Medical Director',
            'summary' => 'Top clinical authority. Approves service denials and appeals; receives critical clinical-safety alerts.',
            'permissions' => [
                'Can issue a Service Delivery Request denial (§460.122 denial notice generated for the participant).',
                'Can decide a §460.122 appeal — uphold or overturn the original denial.',
                'Listed in the grievance-escalation assignee picker.',
            ],
            'notifications' => [
                'Critical alert when a restraint episode goes >4 hours without an observation entry.',
                'Critical alert when a restraint episode IDT review is >24 hours overdue.',
            ],
            'reserved' => [],
        ],

        'compliance_officer' => [
            'label'   => 'Compliance Officer',
            'summary' => 'Owns the grievance + appeals workflow. Auto-named in escalations and overdue alerts.',
            'permissions' => [
                'Can decide a §460.122 appeal — uphold or overturn.',
                'Listed in the grievance-escalation assignee picker.',
            ],
            'notifications' => [
                'Named in alerts on every urgent grievance (72-hour CMS clock per §460.120(c)).',
                'Auto-assigned as the escalation reviewer when a grievance is escalated without a specific reviewer.',
                'Named as the fallback reviewer in overdue-grievance alerts (day 25 of the 30-day clock).',
            ],
            'reserved' => [],
        ],

        'program_director' => [
            'label'   => 'Program Director',
            'summary' => 'Senior administrative role. Today only used as an option in escalation pickers.',
            'permissions' => [
                'Listed in the grievance-escalation assignee picker.',
            ],
            'notifications' => [],
            'reserved' => [
                'Future: organization-wide critical incident alerts (sentinel events, breach incidents). Not yet routed in code.',
            ],
        ],

        'nursing_director' => [
            'label'   => 'Nursing Director',
            'summary' => 'Reserved for nursing-area QA + safety alert routing.',
            'permissions' => [],
            'notifications' => [],
            'reserved' => [
                'Future: nursing-related QA alerts (fall-risk threshold breaches, pressure-injury staging changes, late EMAR/BCMA dose patterns).',
                'Future: critical-value lab acknowledgment escalations when the ordering clinician does not acknowledge within policy.',
                'No code paths route to this designation yet.',
            ],
        ],

        'pharmacy_director' => [
            'label'   => 'Pharmacy Director',
            'summary' => 'Reserved for pharmacy oversight (drug interactions, controlled-substance review).',
            'permissions' => [],
            'notifications' => [],
            'reserved' => [
                'Future: critical drug-interaction alerts on prescribe events.',
                'Future: controlled-substance prescribing pattern alerts and BCMA repeated-override review.',
                'Future: prior-authorization queue oversight notifications.',
                'No code paths route to this designation yet.',
            ],
        ],

        'social_work_supervisor' => [
            'label'   => 'Social Work Supervisor',
            'summary' => 'Reserved for social-work team oversight + escalations.',
            'permissions' => [],
            'notifications' => [],
            'reserved' => [
                'Future: social-determinants escalations (housing instability, food insecurity flagged on intake).',
                'Future: bereavement workflow + family-contact escalations.',
                'No code paths route to this designation yet.',
            ],
        ],
    ];

    protected $fillable = [
        'tenant_id',
        'site_id',
        'first_name',
        'last_name',
        'email',
        'department',
        'role',
        'is_active',
        'last_login_at',
        'failed_login_attempts',
        'locked_until',
        'provisioned_by_user_id',
        'provisioned_at',
        'notification_preferences',
        'theme_preference',
        'designations',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected $casts = [
        'is_active'                  => 'boolean',
        'last_login_at'              => 'datetime',
        'locked_until'               => 'datetime',
        'provisioned_at'             => 'datetime',
        'failed_login_attempts'      => 'integer',
        'notification_preferences'   => 'array',
        'theme_preference'           => 'string',
        'designations'               => 'array',
    ];

    // Passwordless — no password column needed
    public function getAuthPassword(): string
    {
        return '';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function otpCodes(): HasMany
    {
        return $this->hasMany(OtpCode::class, 'user_id');
    }

    public function provisionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provisioned_by_user_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function departmentLabel(): string
    {
        return match ($this->department) {
            'primary_care'      => 'Primary Care / Nursing',
            'therapies'         => 'Therapies (PT/OT/ST)',
            'social_work'       => 'Social Work',
            'behavioral_health' => 'Behavioral Health',
            'dietary'           => 'Dietary / Nutrition',
            'activities'        => 'Activities / Recreation',
            'home_care'         => 'Home Care',
            'transportation'    => 'Transportation',
            'pharmacy'          => 'Pharmacy',
            'idt'               => 'IDT / Care Coordination',
            'enrollment'        => 'Enrollment / Intake',
            'finance'           => 'Finance / Billing',
            'qa_compliance'     => 'QA / Compliance',
            'it_admin'          => 'IT / Administration',
            'executive'         => 'Executive / Leadership',
            'super_admin'       => 'Nostos Super Admin',
            default             => ucfirst($this->department),
        };
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /** Executive users have cross-site, read-only access within their tenant. Phase 10B. */
    public function isExecutive(): bool
    {
        return $this->department === 'executive';
    }

    /** Nostos staff with cross-tenant platform access. Distinct from role='super_admin'. Phase 10B. */
    public function isDeptSuperAdmin(): bool
    {
        return $this->department === 'super_admin';
    }

    // ── Designation helpers ───────────────────────────────────────────────────

    /**
     * Return true if this user holds the given designation key.
     *
     * @param  string  $designation  e.g. 'compliance_officer', 'medical_director'
     */
    public function hasDesignation(string $designation): bool
    {
        return in_array($designation, $this->designations ?? [], true);
    }

    /**
     * Scope: users who hold a specific designation within a query.
     *
     * Usage:
     *   User::where('tenant_id', $id)->withDesignation('compliance_officer')->first()
     */
    public function scopeWithDesignation(Builder $query, string $designation): Builder
    {
        return $query->whereJsonContains('designations', $designation);
    }

    /**
     * Return the human-readable label for a designation key, or the key itself.
     */
    public static function designationLabel(string $designation): string
    {
        return self::DESIGNATION_LABELS[$designation] ?? $designation;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function incrementFailedAttempts(): void
    {
        $this->increment('failed_login_attempts');

        if ($this->failed_login_attempts >= 5) {
            $this->update(['locked_until' => now()->addMinutes(30)]);
        }
    }

    public function resetFailedAttempts(): void
    {
        $this->update([
            'failed_login_attempts' => 0,
            'locked_until'          => null,
        ]);
    }

    // ─── Notification Preferences ─────────────────────────────────────────────

    /**
     * Return the delivery preference for a given notification type key.
     * Falls back to 'in_app_only' if no preference is configured.
     *
     * @param  string  $key  e.g. 'alert_critical', 'sdr_overdue', 'new_message'
     * @return string  'in_app_only' | 'email_immediate' | 'email_digest' | 'off'
     */
    public function notificationPreference(string $key): string
    {
        $prefs = $this->notification_preferences ?? [];
        return $prefs[$key] ?? 'in_app_only';
    }

    // ─── Chat Relationships ───────────────────────────────────────────────────

    public function chatMemberships(): HasMany
    {
        return $this->hasMany(ChatMembership::class, 'user_id');
    }

    public function chatChannels(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            ChatChannel::class,
            'emr_chat_memberships',
            'user_id',
            'channel_id'
        )->withPivot(['joined_at', 'last_read_at']);
    }
}
