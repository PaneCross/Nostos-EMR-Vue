<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractedProviderContract extends Model
{
    use HasFactory;

    protected $table = 'emr_contracted_provider_contracts';

    public const REIMBURSEMENT_BASES = [
        'fee_schedule', 'percent_of_medicare', 'percent_of_billed',
        'flat_per_visit', 'capitation',
    ];

    protected $fillable = [
        'tenant_id', 'contracted_provider_id', 'contract_number',
        'effective_date', 'termination_date',
        'reimbursement_basis', 'reimbursement_value',
        'requires_prior_auth_default', 'notes',
    ];

    protected $casts = [
        'effective_date'              => 'date',
        'termination_date'            => 'date',
        'reimbursement_value'         => 'decimal:4',
        'requires_prior_auth_default' => 'boolean',
    ];

    public function provider(): BelongsTo { return $this->belongsTo(ContractedProvider::class, 'contracted_provider_id'); }
    public function rates(): HasMany      { return $this->hasMany(ContractedProviderRate::class, 'contract_id'); }

    public function rateFor(string $cptCode, ?string $modifier = null): ?float
    {
        $rate = $this->rates()
            ->where('cpt_code', $cptCode)
            ->when($modifier !== null, fn ($q) => $q->where('modifier', $modifier))
            ->when($modifier === null, fn ($q) => $q->whereNull('modifier'))
            ->first();
        return $rate ? (float) $rate->rate_amount : null;
    }
}
