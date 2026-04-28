<?php

// ─── StaffTrainingRecord ──────────────────────────────────────────────────────
// 42 CFR §460.71 : PACE staff training hours tracked as discrete events.
// Used to compute annual training-hour totals by category.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffTrainingRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_staff_training_records';

    public const CATEGORIES = [
        'direct_care',
        'hipaa',
        'infection_control',
        'dementia_care',
        'abuse_neglect',
        'fire_safety',
        'orientation',
        'clinical',
        'compliance',
        'other',
    ];

    public const CATEGORY_LABELS = [
        'direct_care'       => 'Direct Care',
        'hipaa'             => 'HIPAA',
        'infection_control' => 'Infection Control',
        'dementia_care'     => 'Dementia Care',
        'abuse_neglect'     => 'Abuse / Neglect',
        'fire_safety'       => 'Fire Safety',
        'orientation'       => 'Orientation',
        'clinical'          => 'Clinical',
        'compliance'        => 'Compliance',
        'other'             => 'Other',
    ];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'credential_id',
        'training_name',
        'category',
        'training_hours',
        'completed_at',
        'verified_at',
        'verified_by_user_id',
        'document_path',
        'document_filename',
        'notes',
    ];

    protected $casts = [
        'training_hours' => 'decimal:2',
        'completed_at'   => 'date',
        'verified_at'    => 'datetime',
    ];

    public function tenant(): BelongsTo     { return $this->belongsTo(Tenant::class); }
    public function user(): BelongsTo       { return $this->belongsTo(User::class, 'user_id'); }
    public function verifiedBy(): BelongsTo { return $this->belongsTo(User::class, 'verified_by_user_id'); }
    /** Optional credential this training counts CEU hours toward (V2 linkage). */
    public function credential(): BelongsTo { return $this->belongsTo(StaffCredential::class, 'credential_id'); }

    public function scopeForTenant(Builder $q, int $tenantId): Builder
    {
        return $q->where('tenant_id', $tenantId);
    }

    public function scopeCompletedBetween(Builder $q, string $start, string $end): Builder
    {
        return $q->whereBetween('completed_at', [$start, $end]);
    }
}
