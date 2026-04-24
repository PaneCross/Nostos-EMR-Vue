<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BeersCriterion extends Model
{
    protected $table = 'emr_beers_criteria';

    protected $fillable = [
        'drug_keyword', 'risk_category', 'rationale',
        'recommendation', 'evidence_quality',
    ];

    public static function forDrugName(string $drugName): \Illuminate\Support\Collection
    {
        return static::query()
            ->whereRaw('? ILIKE CONCAT(\'%\', drug_keyword, \'%\')', [$drugName])
            ->get();
    }
}
