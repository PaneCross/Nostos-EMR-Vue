<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PredictiveModelVersion extends Model
{
    protected $table = 'emr_predictive_model_versions';
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'risk_type', 'version_number', 'algorithm',
        'coefficients', 'training_accuracy', 'training_sample_size',
        'trained_at', 'created_at',
    ];

    protected $casts = [
        'coefficients' => 'array',
        'trained_at'   => 'datetime',
        'created_at'   => 'datetime',
    ];

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
    public function scopeForRiskType($q, string $r) { return $q->where('risk_type', $r); }
}
