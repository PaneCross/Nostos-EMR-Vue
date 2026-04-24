<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QualityMeasureSnapshot extends Model
{
    protected $table = 'emr_quality_measure_snapshots';

    protected $fillable = [
        'tenant_id', 'measure_id', 'numerator', 'denominator', 'rate_pct', 'computed_at',
    ];

    protected $casts = [
        'rate_pct'    => 'decimal:2',
        'computed_at' => 'datetime',
    ];

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
}
