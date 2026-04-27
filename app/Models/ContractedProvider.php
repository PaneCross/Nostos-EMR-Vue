<?php

// ─── ContractedProvider : Phase S2 ──────────────────────────────────────────
// Network of external specialists/hospitals/SNFs/imaging/labs/etc. that the
// PACE program contracts with for participant care.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractedProvider extends Model
{
    use HasFactory;

    protected $table = 'emr_contracted_providers';

    public const PROVIDER_TYPES = [
        'specialist', 'hospital', 'snf', 'imaging', 'lab',
        'pharmacy', 'dme', 'behavioral_health', 'other',
    ];

    protected $fillable = [
        'tenant_id', 'name', 'npi', 'tax_id',
        'provider_type', 'specialty',
        'phone', 'fax', 'address_line1', 'city', 'state', 'zip',
        'accepting_new_referrals', 'is_active', 'notes',
    ];

    protected $casts = [
        'accepting_new_referrals' => 'boolean',
        'is_active'               => 'boolean',
    ];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function contracts(): HasMany { return $this->hasMany(ContractedProviderContract::class, 'contracted_provider_id'); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }
    public function scopeActive($q)            { return $q->where('is_active', true); }

    public function activeContract(): ?ContractedProviderContract
    {
        $today = now()->toDateString();
        return $this->contracts()
            ->where('effective_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('termination_date')->orWhere('termination_date', '>=', $today);
            })
            ->orderByDesc('effective_date')
            ->first();
    }
}
