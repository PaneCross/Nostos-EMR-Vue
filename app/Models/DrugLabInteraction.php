<?php

// ─── DrugLabInteraction ──────────────────────────────────────────────────────
// Phase B5. Reference (non-tenant) data mapping drug-name keywords to the
// labs that must be monitored + frequency + critical thresholds. Seeded
// via DrugLabInteractionSeeder.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DrugLabInteraction extends Model
{
    protected $table = 'emr_drug_lab_interactions';

    protected $fillable = [
        'drug_keyword', 'lab_name', 'loinc_code',
        'monitoring_frequency_days',
        'critical_low', 'critical_high', 'units', 'notes',
    ];

    protected $casts = [
        'critical_low'  => 'decimal:3',
        'critical_high' => 'decimal:3',
    ];

    /** Look up interactions for a given drug name using ILIKE matching on drug_keyword. */
    public static function forDrugName(string $drugName): \Illuminate\Support\Collection
    {
        return static::query()
            ->whereRaw('? ILIKE CONCAT(\'%\', drug_keyword, \'%\')', [$drugName])
            ->get();
    }
}
