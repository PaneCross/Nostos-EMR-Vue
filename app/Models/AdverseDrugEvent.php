<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdverseDrugEvent extends Model
{
    use HasFactory;

    protected $table = 'emr_adverse_drug_events';

    public const SEVERITIES  = ['mild', 'moderate', 'severe', 'life_threatening', 'fatal'];
    public const CAUSALITIES = ['definite', 'probable', 'possible', 'unlikely'];

    /** Severities that require MedWatch reporting within 15 days. */
    public const MEDWATCH_REQUIRED_SEVERITIES = ['severe', 'life_threatening', 'fatal'];

    /** Severities that auto-create an Allergy row to prevent re-administration. */
    public const AUTO_ALLERGY_SEVERITIES = ['severe', 'life_threatening', 'fatal'];

    /** FDA MedWatch response-window. */
    public const MEDWATCH_DEADLINE_DAYS = 15;

    protected $fillable = [
        'tenant_id', 'participant_id', 'medication_id',
        'onset_date', 'severity', 'reaction_description', 'causality',
        'reporter_user_id', 'reported_to_medwatch_at', 'medwatch_tracking_number',
        'outcome_text', 'auto_allergy_created',
    ];

    protected $casts = [
        'onset_date'              => 'date',
        'reported_to_medwatch_at' => 'datetime',
        'auto_allergy_created'    => 'boolean',
    ];

    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }
    public function medication(): BelongsTo  { return $this->belongsTo(Medication::class); }
    public function reporter(): BelongsTo    { return $this->belongsTo(User::class, 'reporter_user_id'); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }

    public function requiresMedwatch(): bool
    {
        return in_array($this->severity, self::MEDWATCH_REQUIRED_SEVERITIES, true);
    }

    public function medwatchOverdue(): bool
    {
        if (! $this->requiresMedwatch()) return false;
        if ($this->reported_to_medwatch_at !== null) return false;
        return $this->onset_date?->addDays(self::MEDWATCH_DEADLINE_DAYS)->isPast() ?? false;
    }
}
