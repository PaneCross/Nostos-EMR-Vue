<?php

// ─── ReferralNote Model ───────────────────────────────────────────────────────
// Append-only note on an enrollment Referral. Used to track context, follow-up,
// and blockers through the 9-status enrollment state machine. Notes are never
// edited or deleted : once written, they stand.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralNote extends Model
{
    use HasFactory;

    protected $table = 'emr_referral_notes';

    /** Immutable after create : no updated_at column. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'referral_id',
        'user_id',
        'content',
        'referral_status',  // snapshot of referral.status at note-write time
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeForReferral(Builder $query, int $referralId): Builder
    {
        return $query->where('referral_id', $referralId);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
