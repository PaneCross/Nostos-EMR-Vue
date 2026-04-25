<?php

// ─── IadlRecord ──────────────────────────────────────────────────────────────
// Phase C1. Snapshot-style Lawton IADL assessment. Append-only — one row per
// complete assessment.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IadlRecord extends Model
{
    use HasFactory;

    protected $table = 'emr_iadl_records';

    public const ITEMS = [
        'telephone', 'shopping', 'food_preparation', 'housekeeping',
        'laundry', 'transportation', 'medications', 'finances',
    ];

    public const ITEM_LABELS = [
        'telephone'        => 'Telephone use',
        'shopping'         => 'Shopping',
        'food_preparation' => 'Food preparation',
        'housekeeping'     => 'Housekeeping',
        'laundry'          => 'Laundry',
        'transportation'   => 'Transportation',
        'medications'      => 'Responsibility for own medications',
        'finances'         => 'Ability to handle finances',
    ];

    public const INTERPRETATIONS = [
        'independent', 'mild_impairment', 'moderate_impairment', 'severe_impairment',
    ];

    /** Items whose impairment commonly drives a specific care-plan referral. */
    public const REFERRAL_SUGGESTIONS = [
        'finances'    => ['dept' => 'social_work', 'goal' => 'Social-work referral for financial-management support'],
        'medications' => ['dept' => 'pharmacy',    'goal' => 'Pharmacy consult for medication-management support (pill box, bubble pack, home care med admin)'],
        'food_preparation' => ['dept' => 'dietary', 'goal' => 'Dietary consult for nutrition support (meals on wheels, day-center meals, home delivery)'],
        'transportation'   => ['dept' => 'transportation', 'goal' => 'Transportation service referral for medical + essential errands'],
    ];

    protected $fillable = [
        'tenant_id', 'participant_id', 'recorded_by_user_id', 'recorded_at',
        ...self::ITEMS,
        'total_score', 'interpretation', 'notes',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }
    public function tenant(): BelongsTo      { return $this->belongsTo(Tenant::class); }
    public function recordedBy(): BelongsTo  { return $this->belongsTo(User::class, 'recorded_by_user_id'); }

    public function scopeForTenant($q, int $t)        { return $q->where('tenant_id', $t); }
    public function scopeForParticipant($q, int $pid) { return $q->where('participant_id', $pid); }

    /**
     * Return items that are 0 (impaired) from this record.
     * @return array<int, string>
     */
    public function impairedItems(): array
    {
        $out = [];
        foreach (self::ITEMS as $item) {
            if ((int) $this->{$item} === 0) $out[] = $item;
        }
        return $out;
    }

    /**
     * Return referral suggestions triggered by this record's impaired items.
     * @return array<int, array{item: string, dept: string, goal: string}>
     */
    public function referralSuggestions(): array
    {
        $out = [];
        foreach ($this->impairedItems() as $item) {
            if (isset(self::REFERRAL_SUGGESTIONS[$item])) {
                $out[] = array_merge(['item' => $item], self::REFERRAL_SUGGESTIONS[$item]);
            }
        }
        return $out;
    }
}
