<?php

// ─── SnomedLookup ────────────────────────────────────────────────────────────
// Phase 13.1. Tiny shared lookup table of SNOMED CT codes relevant to PACE
// participants (cardio, diabetes, dementia, frailty, fall-related). Not a
// full SNOMED distribution : licensing + size make that untenable for MVP.
// Used to autocomplete the SNOMED field on the Problem list.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SnomedLookup extends Model
{
    protected $table = 'shared_snomed_lookup';

    protected $fillable = ['code', 'display', 'category', 'icd10_code'];

    public function scopeSearch($q, string $term)
    {
        $like = '%' . str_replace(' ', '%', $term) . '%';
        return $q->where(function ($w) use ($like, $term) {
            $w->where('display', 'ilike', $like)
              ->orWhere('code', 'ilike', $term . '%')
              ->orWhere('icd10_code', 'ilike', $term . '%');
        });
    }
}
