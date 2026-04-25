<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EligibilityCheck extends Model
{
    protected $table = 'emr_eligibility_checks';

    public const PAYER_TYPES   = ['medicare', 'medicaid', 'other'];
    public const STATUSES      = ['verified', 'inactive', 'denied', 'error', 'unverified'];

    protected $fillable = [
        'tenant_id', 'participant_id', 'payer_type', 'member_id_lookup',
        'requested_at', 'response_status', 'response_payload_json',
        'gateway_used', 'requested_by_user_id',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'response_payload_json' => 'array',
    ];

    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
    public function scopeForParticipant($q, int $pid) { return $q->where('participant_id', $pid); }
}
