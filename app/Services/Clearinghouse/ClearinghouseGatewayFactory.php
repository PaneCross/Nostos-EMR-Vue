<?php

// ─── ClearinghouseGatewayFactory ─────────────────────────────────────────────
// Phase 12. Resolves the right ClearinghouseGateway implementation for a
// given tenant. If no active config exists for the tenant, a synthetic
// NullClearinghouseGateway + NULL config is returned — the EMR ALWAYS has a
// working transmission path, even if that path is "stage for manual upload."
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services\Clearinghouse;

use App\Models\ClearinghouseConfig;

class ClearinghouseGatewayFactory
{
    /**
     * @return array{0: ClearinghouseGateway, 1: ClearinghouseConfig}
     */
    public function forTenant(int $tenantId): array
    {
        $cfg = ClearinghouseConfig::forTenant($tenantId)->active()->first();

        if (! $cfg) {
            // Synthetic in-memory config (not persisted). Gateway writes
            // transmission rows with config_id=null.
            $cfg = new ClearinghouseConfig([
                'tenant_id'     => $tenantId,
                'adapter'       => 'null_gateway',
                'display_name'  => 'Default — no vendor configured',
                'environment'   => 'sandbox',
                'is_active'     => true,
            ]);
            $cfg->id = null;
        }

        return [$this->resolve($cfg->adapter), $cfg];
    }

    public function resolve(string $adapter): ClearinghouseGateway
    {
        return match ($adapter) {
            'availity'          => app(AvailityClearinghouseGateway::class),
            'change_healthcare' => app(ChangeHealthcareClearinghouseGateway::class),
            'office_ally'       => app(OfficeAllyClearinghouseGateway::class),
            default             => app(NullClearinghouseGateway::class),
        };
    }
}
