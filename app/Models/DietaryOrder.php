<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DietaryOrder extends Model
{
    use HasFactory;

    protected $table = 'emr_dietary_orders';

    public const DIET_TYPES = [
        'regular', 'diabetic', 'renal', 'cardiac', 'low_sodium',
        'pureed', 'mechanical_soft', 'npo', 'other',
    ];

    protected $fillable = [
        'tenant_id', 'participant_id', 'ordered_by_user_id',
        'diet_type', 'calorie_target', 'fluid_restriction_ml_per_day',
        'texture_modification', 'allergen_exclusions',
        'effective_date', 'discontinued_date', 'rationale', 'notes',
    ];

    protected $casts = [
        'effective_date'    => 'date',
        'discontinued_date' => 'date',
    ];

    public function participant(): BelongsTo { return $this->belongsTo(Participant::class); }
    public function orderedBy(): BelongsTo   { return $this->belongsTo(User::class, 'ordered_by_user_id'); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
    public function scopeActive($q)            { return $q->whereNull('discontinued_date'); }
}
