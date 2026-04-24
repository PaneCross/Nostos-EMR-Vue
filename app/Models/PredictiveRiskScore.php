<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PredictiveRiskScore extends Model
{
    protected $table = 'emr_predictive_risk_scores';

    public const RISK_TYPES = ['disenrollment', 'acute_event'];
    public const BANDS = ['low', 'medium', 'high'];

    protected $fillable = [
        'tenant_id', 'participant_id', 'model_version',
        'risk_type', 'score', 'band', 'factors', 'computed_at',
    ];

    protected $casts = [
        'factors'     => 'array',
        'computed_at' => 'datetime',
    ];

    public function participant(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Participant::class); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
    public function scopeHigh($q) { return $q->where('band', 'high'); }
}
