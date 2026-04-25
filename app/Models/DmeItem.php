<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DmeItem extends Model
{
    use HasFactory;

    protected $table = 'emr_dme_items';

    public const STATUSES = ['available', 'issued', 'servicing', 'retired', 'lost'];

    protected $fillable = [
        'tenant_id', 'item_type', 'manufacturer', 'model', 'serial_number',
        'hcpcs_code', 'purchase_date', 'purchase_cost',
        'status', 'next_service_due', 'notes',
    ];

    protected $casts = [
        'purchase_date'    => 'date',
        'next_service_due' => 'date',
        'purchase_cost'    => 'decimal:2',
    ];

    public function issuances(): HasMany { return $this->hasMany(DmeIssuance::class); }

    public function scopeForTenant($q, int $t) { return $q->where('tenant_id', $t); }

    public function activeIssuance(): ?DmeIssuance
    {
        return $this->issuances()->whereNull('returned_at')->latest('issued_at')->first();
    }
}
