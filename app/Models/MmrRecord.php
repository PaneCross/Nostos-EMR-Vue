<?php

// ─── MmrRecord ────────────────────────────────────────────────────────────────
// One CMS MMR line item (one member per period). Carries the discrepancy
// detection result computed at parse time.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MmrRecord extends Model
{
    use HasFactory;

    protected $table = 'emr_mmr_records';

    // Discrepancy type constants — used in scopes, controllers, and the UI.
    public const DISC_NONE                    = null;
    public const DISC_CMS_ENROLLED_NOT_LOCAL  = 'cms_enrolled_not_local';  // CMS says enrolled, we don't have them
    public const DISC_CMS_DISENROLLED_LOCAL_ENROLLED = 'cms_disenrolled_local_enrolled';
    public const DISC_CAPITATION_VARIANCE     = 'capitation_variance';      // amount mismatch vs expected
    public const DISC_RETROACTIVE_ADJUSTMENT  = 'retroactive_adjustment';   // non-zero adjustment_amount
    public const DISC_UNMATCHED_MBI           = 'unmatched_mbi';            // no local participant with this MBI

    public const DISC_LABELS = [
        'cms_enrolled_not_local'          => 'CMS Enrolled, Not Local',
        'cms_disenrolled_local_enrolled'  => 'CMS Disenrolled, Locally Enrolled',
        'capitation_variance'             => 'Capitation Variance',
        'retroactive_adjustment'          => 'Retroactive Adjustment',
        'unmatched_mbi'                   => 'Unmatched MBI',
    ];

    public const RESOLUTION_OPEN     = 'open';
    public const RESOLUTION_RESOLVED = 'resolved';
    public const RESOLUTION_IGNORED  = 'ignored';

    protected $fillable = [
        'tenant_id',
        'mmr_file_id',
        'medicare_id',
        'member_name',
        'member_status',
        'enrolled_from',
        'enrolled_through',
        'capitation_amount',
        'adjustment_amount',
        'raw_payload',
        'matched_participant_id',
        'discrepancy_type',
        'discrepancy_note',
        'resolution_status',
        'resolved_at',
        'resolved_by_user_id',
        'resolution_notes',
    ];

    protected $casts = [
        'enrolled_from'     => 'date',
        'enrolled_through'  => 'date',
        'capitation_amount' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'raw_payload'       => 'array',
        'resolved_at'       => 'datetime',
    ];

    public function tenant(): BelongsTo       { return $this->belongsTo(Tenant::class); }
    public function file(): BelongsTo         { return $this->belongsTo(MmrFile::class, 'mmr_file_id'); }
    public function participant(): BelongsTo  { return $this->belongsTo(Participant::class, 'matched_participant_id'); }
    public function resolvedBy(): BelongsTo   { return $this->belongsTo(User::class, 'resolved_by_user_id'); }

    public function scopeForTenant(Builder $q, int $tenantId): Builder
    {
        return $q->where('tenant_id', $tenantId);
    }

    public function scopeOpenDiscrepancies(Builder $q): Builder
    {
        return $q->whereNotNull('discrepancy_type')->where('resolution_status', self::RESOLUTION_OPEN);
    }
}
