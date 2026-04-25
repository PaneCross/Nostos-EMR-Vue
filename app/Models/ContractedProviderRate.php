<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractedProviderRate extends Model
{
    protected $table = 'emr_contracted_provider_rates';

    protected $fillable = ['contract_id', 'cpt_code', 'rate_amount', 'modifier', 'notes'];

    protected $casts = ['rate_amount' => 'decimal:2'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ContractedProviderContract::class, 'contract_id');
    }
}
