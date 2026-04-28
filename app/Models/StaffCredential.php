<?php

// ─── StaffCredential ──────────────────────────────────────────────────────────
// 42 CFR §460.64-71 + CMS Personnel Audit Protocol.
// Tracks licenses, TB clearances, certifications, immunizations, and
// competencies for PACE staff.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffCredential extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_staff_credentials';

    public const TYPE_LICENSE        = 'license';
    public const TYPE_TB_CLEARANCE   = 'tb_clearance';
    public const TYPE_TRAINING       = 'training';
    public const TYPE_COMPETENCY     = 'competency';
    public const TYPE_CERTIFICATION  = 'certification';
    public const TYPE_IMMUNIZATION   = 'immunization';
    public const TYPE_BACKGROUND     = 'background_check';
    public const TYPE_OTHER          = 'other';

    public const TYPES = [
        self::TYPE_LICENSE, self::TYPE_TB_CLEARANCE, self::TYPE_TRAINING,
        self::TYPE_COMPETENCY, self::TYPE_CERTIFICATION, self::TYPE_IMMUNIZATION,
        self::TYPE_BACKGROUND, self::TYPE_OTHER,
    ];

    public const TYPE_LABELS = [
        self::TYPE_LICENSE       => 'Professional License',
        self::TYPE_TB_CLEARANCE  => 'TB Clearance',
        self::TYPE_TRAINING      => 'Training',
        self::TYPE_COMPETENCY    => 'Competency Evaluation',
        self::TYPE_CERTIFICATION => 'Certification',
        self::TYPE_IMMUNIZATION  => 'Immunization',
        self::TYPE_BACKGROUND    => 'Background Check',
        self::TYPE_OTHER         => 'Other',
    ];

    /** Alert threshold windows (days out from expires_at) that drive widgets + job. */
    public const ALERT_DAYS = [60, 30, 14, 0];

    /** Verification source : how the credential was primary-source-verified per §460.64(c). */
    public const VERIFICATION_SOURCES = [
        'state_board'      => 'State Licensing Board',
        'npdb'             => 'NPDB Lookup',
        'uploaded_doc'     => 'Uploaded Document',
        'self_attestation' => 'Self-Attestation',
        'other'            => 'Other',
    ];

    /** Status overrides ; allows recording suspended/revoked even before expiration. */
    public const CMS_STATUSES = [
        'active'    => 'Active',
        'expired'   => 'Expired',
        'suspended' => 'Suspended',
        'revoked'   => 'Revoked',
        'pending'   => 'Pending',
    ];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'credential_definition_id',
        'credential_type',
        'title',
        'license_state',
        'license_number',
        'issued_at',
        'expires_at',
        'verified_at',
        'verified_by_user_id',
        'verification_source',
        'cms_status',
        'replaced_by_credential_id',
        'document_path',
        'document_filename',
        'notes',
    ];

    protected $casts = [
        'issued_at'   => 'date',
        'expires_at'  => 'date',
        'verified_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo     { return $this->belongsTo(Tenant::class); }
    public function user(): BelongsTo       { return $this->belongsTo(User::class, 'user_id'); }
    public function verifiedBy(): BelongsTo { return $this->belongsTo(User::class, 'verified_by_user_id'); }
    public function definition(): BelongsTo { return $this->belongsTo(CredentialDefinition::class, 'credential_definition_id'); }
    public function replacedBy(): BelongsTo { return $this->belongsTo(self::class, 'replaced_by_credential_id'); }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForTenant(Builder $q, int $tenantId): Builder
    {
        return $q->where('tenant_id', $tenantId);
    }

    public function scopeExpiringWithin(Builder $q, int $days): Builder
    {
        return $q->whereNotNull('expires_at')
                 ->where('expires_at', '<=', now()->addDays($days)->toDateString())
                 ->where('expires_at', '>=', now()->toDateString());
    }

    public function scopeExpired(Builder $q): Builder
    {
        return $q->whereNotNull('expires_at')->where('expires_at', '<', now()->toDateString());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function daysUntilExpiration(): ?int
    {
        if (! $this->expires_at) return null;
        return (int) floor(now()->startOfDay()->diffInDays($this->expires_at, false));
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isExpiringSoon(int $days = 60): bool
    {
        $d = $this->daysUntilExpiration();
        return $d !== null && $d >= 0 && $d <= $days;
    }

    /** Severity bucket for UI coloring. Honors cms_status overrides (suspended/revoked). */
    public function status(): string
    {
        // Explicit overrides take precedence over date math.
        if ($this->cms_status === 'suspended') return 'suspended';
        if ($this->cms_status === 'revoked')   return 'revoked';
        if ($this->cms_status === 'pending')   return 'pending';

        $d = $this->daysUntilExpiration();
        if ($d === null) return 'no_expiry';
        if ($d < 0)      return 'expired';
        if ($d === 0)    return 'due_today';
        if ($d <= 14)    return 'due_14';
        if ($d <= 30)    return 'due_30';
        if ($d <= 60)    return 'due_60';
        return 'current';
    }

    /** True when cms_status indicates the credential is unusable regardless of dates. */
    public function isInvalidStatus(): bool
    {
        return in_array($this->cms_status, ['suspended', 'revoked', 'pending'], true);
    }
}
