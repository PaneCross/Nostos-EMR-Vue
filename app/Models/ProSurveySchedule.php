<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProSurveySchedule extends Model
{
    protected $table = 'emr_pro_survey_schedules';

    protected $fillable = [
        'tenant_id', 'participant_id', 'survey_id',
        'next_send_at', 'last_sent_at', 'is_active',
    ];

    protected $casts = [
        'next_send_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'is_active'    => 'boolean',
    ];
}
