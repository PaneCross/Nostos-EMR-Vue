<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PriorAuthRequest extends Model
{
    protected $table = 'emr_prior_auth_requests';

    public const STATUSES = ['draft', 'submitted', 'approved', 'denied', 'withdrawn', 'expired'];
    public const OPEN_STATUSES = ['draft', 'submitted'];
    public const URGENCIES = ['standard', 'expedited'];
    public const PAYER_TYPES = ['medicare_d', 'medicaid', 'other'];

    protected $fillable = [
        'tenant_id', 'participant_id', 'related_to_type', 'related_to_id',
        'payer_type', 'justification_text', 'urgency', 'status',
        'submitted_at', 'decision_at', 'decision_rationale',
        'expiration_date', 'approval_reference',
        'requested_by_user_id', 'decided_by_user_id',
    ];

    protected $casts = [
        'submitted_at'      => 'datetime',
        'decision_at'       => 'datetime',
        'expiration_date'   => 'date',
    ];

    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }
    public function relatedTo(): MorphTo      { return $this->morphTo(null, 'related_to_type', 'related_to_id'); }
    public function requestedBy(): BelongsTo  { return $this->belongsTo(User::class, 'requested_by_user_id'); }
    public function decidedBy(): BelongsTo    { return $this->belongsTo(User::class, 'decided_by_user_id'); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
    public function scopeOpen($q)              { return $q->whereIn('status', self::OPEN_STATUSES); }
    public function scopeExpiringWithin($q, int $days) { return $q->where('expiration_date', '<=', now()->addDays($days)->toDateString())->where('expiration_date', '>=', now()->toDateString()); }
}
