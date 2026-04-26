<?php

// ─── Tenant ───────────────────────────────────────────────────────────────────
// Top-level multi-tenancy root. One row per PACE (Programs of All-Inclusive
// Care for the Elderly) organization served by this EMR install.
//
// A tenant owns Sites (physical day-center locations), Users (staff), and —
// indirectly through Site/Participant — every clinical record in the system.
// `cms_contract_id` is the federal Centers for Medicare & Medicaid Services
// contract number issued to the PACE org. `transport_mode` chooses whether
// participant rides are dispatched by an outside broker or in-house fleet.
//
// Notable rules:
//  - Tenant-scoped: every clinical query MUST filter by tenant_id. Cross-
//    tenant access is a HIPAA breach (45 CFR §164.312 access controls).
//  - `auto_logout_minutes` per-tenant tunes the inactivity-timeout safeguard.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $table = 'shared_tenants';

    protected $fillable = [
        'name',
        'slug',
        'transport_mode',
        'cms_contract_id',
        'state',
        'timezone',
        'auto_logout_minutes',
        'is_active',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'auto_logout_minutes'  => 'integer',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'tenant_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'tenant_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class, 'tenant_id');
    }

    public function isBrokerMode(): bool
    {
        return $this->transport_mode === 'broker';
    }
}
