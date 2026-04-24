<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProSurvey extends Model
{
    protected $table = 'emr_pro_surveys';

    protected $fillable = ['tenant_id', 'key', 'title', 'questions', 'cadence'];
    protected $casts = ['questions' => 'array'];
}
