<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QualityMeasure extends Model
{
    protected $table = 'emr_quality_measures';

    protected $fillable = [
        'measure_id', 'name', 'category',
        'numerator_definition', 'denominator_definition', 'data_source',
    ];
}
