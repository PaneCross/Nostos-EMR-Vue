<?php

// ─── CarcCode ──────────────────────────────────────────────────────────────────
//
// Lookup table for X12 Claim Adjustment Reason Codes (CARCs).
// CARCs are standardized codes used in X12 835 CAS segments to explain why
// a payer adjusted a claim. Published by Washington Publishing Company (WPC)
// under contract with CMS.
//
// Append-only (UPDATED_AT = null) : reference data; updated only via seeder
// when CMS publishes a new CARC code set.
//
// Populated by CarcCodeSeeder with ~55 commonly encountered codes.
// is_denial_indicator = true when a CO-group adjustment with this code means
// the claim was truly denied (vs. a routine contractual write-off).

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarcCode extends Model
{
    protected $table = 'emr_carc_codes';

    /** Disable updated_at : reference data is append-only. */
    public const UPDATED_AT = null;

    // ── Fillable ───────────────────────────────────────────────────────────────

    protected $fillable = [
        'code',
        'description',
        'notes',
        'is_denial_indicator',
        'denial_category',
        'is_active',
    ];

    protected $casts = [
        'is_denial_indicator' => 'boolean',
        'is_active'           => 'boolean',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    /** All remittance adjustments that used this CARC code. */
    public function adjustments(): HasMany
    {
        return $this->hasMany(RemittanceAdjustment::class, 'reason_code', 'code');
    }

    // ── Query Scopes ───────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDenialIndicators($query)
    {
        return $query->where('is_denial_indicator', true);
    }

    public function scopeForCategory($query, string $category)
    {
        return $query->where('denial_category', $category);
    }

    // ── Business logic ─────────────────────────────────────────────────────────

    /**
     * Look up a CARC code by its code value.
     * Returns null if not found (handles codes not yet in our lookup table).
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->where('is_active', true)->first();
    }

    /**
     * Get the denial category for a given code value, or 'other' if not found.
     * Used by Remittance835ParserService when creating denial records.
     */
    public static function categoryForCode(string $code): string
    {
        $carc = static::findByCode($code);
        return $carc?->denial_category ?? 'other';
    }

    // ── API Serialization ──────────────────────────────────────────────────────

    public function toApiArray(): array
    {
        return [
            'id'                   => $this->id,
            'code'                 => $this->code,
            'description'          => $this->description,
            'notes'                => $this->notes,
            'is_denial_indicator'  => $this->is_denial_indicator,
            'denial_category'      => $this->denial_category,
            'is_active'            => $this->is_active,
        ];
    }
}
