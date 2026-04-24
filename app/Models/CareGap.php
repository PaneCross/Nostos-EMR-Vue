<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareGap extends Model
{
    protected $table = 'emr_care_gaps';

    public const MEASURES = [
        'annual_pcp_visit', 'flu_shot', 'pneumococcal',
        'colonoscopy', 'mammogram', 'a1c', 'diabetic_eye_exam',
    ];

    public const MEASURE_LABELS = [
        'annual_pcp_visit'   => 'Annual PCP Visit',
        'flu_shot'           => 'Flu Shot (This Season)',
        'pneumococcal'       => 'Pneumococcal Vaccine',
        'colonoscopy'        => 'Colonoscopy (10y, ages 45-75)',
        'mammogram'          => 'Mammogram (2y, age 40+ female)',
        'a1c'                => 'A1c (6mo if diabetic)',
        'diabetic_eye_exam'  => 'Diabetic Eye Exam (12mo)',
    ];

    protected $fillable = [
        'tenant_id', 'participant_id', 'measure', 'satisfied',
        'last_satisfied_date', 'next_due_date', 'reason_open', 'calculated_at',
    ];

    protected $casts = [
        'satisfied'            => 'boolean',
        'last_satisfied_date'  => 'date',
        'next_due_date'        => 'date',
        'calculated_at'        => 'datetime',
    ];

    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
    public function scopeOpen($q) { return $q->where('satisfied', false); }
}
