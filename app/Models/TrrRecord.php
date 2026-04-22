<?php

// ─── TrrRecord ────────────────────────────────────────────────────────────────
// One CMS TRR line item (one transaction reply per row).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrrRecord extends Model
{
    use HasFactory;

    protected $table = 'emr_trr_records';

    // Common CMS transaction codes (partial list; honest-labeled placeholders).
    public const TRANSACTION_CODE_LABELS = [
        '01' => 'Enrollment',
        '51' => 'Disenrollment',
        '61' => 'PBP Change',
        '71' => 'Address Change',
        '81' => 'Reinstatement',
    ];

    public const RESULT_ACCEPTED      = 'accepted';
    public const RESULT_REJECTED      = 'rejected';
    public const RESULT_PENDING       = 'pending';
    public const RESULT_INFORMATIONAL = 'informational';

    protected $fillable = [
        'tenant_id',
        'trr_file_id',
        'medicare_id',
        'transaction_code',
        'transaction_label',
        'transaction_result',
        'trc_code',
        'trc_description',
        'effective_date',
        'transaction_date',
        'raw_payload',
        'matched_participant_id',
    ];

    protected $casts = [
        'effective_date'   => 'date',
        'transaction_date' => 'date',
        'raw_payload'      => 'array',
    ];

    public function tenant(): BelongsTo       { return $this->belongsTo(Tenant::class); }
    public function file(): BelongsTo         { return $this->belongsTo(TrrFile::class, 'trr_file_id'); }
    public function participant(): BelongsTo  { return $this->belongsTo(Participant::class, 'matched_participant_id'); }

    public function scopeForTenant(Builder $q, int $tenantId): Builder
    {
        return $q->where('tenant_id', $tenantId);
    }

    public function scopeRejected(Builder $q): Builder
    {
        return $q->where('transaction_result', self::RESULT_REJECTED);
    }
}
