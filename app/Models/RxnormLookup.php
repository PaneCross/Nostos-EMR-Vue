<?php

// ─── RxnormLookup ────────────────────────────────────────────────────────────
// Phase 13.1. Tiny shared lookup table of RxNorm codes covering common PACE
// medications + likely drug-allergy codes. Autocompletes the RxNorm field on
// Medications and Allergies.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RxnormLookup extends Model
{
    protected $table = 'shared_rxnorm_lookup';

    protected $fillable = ['code', 'display', 'tty', 'is_allergen_candidate'];

    protected $casts = [
        'is_allergen_candidate' => 'boolean',
    ];

    public function scopeSearch($q, string $term)
    {
        $like = '%' . str_replace(' ', '%', $term) . '%';
        return $q->where(function ($w) use ($like, $term) {
            $w->where('display', 'ilike', $like)
              ->orWhere('code', 'ilike', $term . '%');
        });
    }

    public function scopeAllergenCandidates($q)
    {
        return $q->where('is_allergen_candidate', true);
    }
}
