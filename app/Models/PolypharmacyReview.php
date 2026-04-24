<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolypharmacyReview extends Model
{
    protected $table = 'emr_polypharmacy_reviews';

    /** ≥10 active meds triggers queue. */
    public const POLYPHARMACY_THRESHOLD = 10;

    /** Re-queue participant every 180 days. */
    public const REVIEW_INTERVAL_DAYS = 180;

    protected $fillable = [
        'tenant_id', 'participant_id',
        'active_med_count_at_queue', 'queued_at',
        'reviewed_at', 'reviewed_by_user_id',
        'deprescribing_recommendations', 'pim_flags_at_review', 'notes',
    ];

    protected $casts = [
        'queued_at'           => 'datetime',
        'reviewed_at'         => 'datetime',
        'pim_flags_at_review' => 'array',
    ];

    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }
    public function reviewedBy(): BelongsTo  { return $this->belongsTo(User::class, 'reviewed_by_user_id'); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
    public function scopePending($q)           { return $q->whereNull('reviewed_at'); }
}
