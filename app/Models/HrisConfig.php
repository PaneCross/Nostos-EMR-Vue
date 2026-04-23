<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Phase 15.7 — HRIS provider configuration (per-tenant, one row per provider).
class HrisConfig extends Model
{
    protected $table = 'emr_hris_configs';

    public const PROVIDERS = ['bamboohr', 'rippling', 'gusto', 'custom'];

    protected $fillable = [
        'tenant_id', 'provider', 'webhook_secret_hash',
        'credentials_json', 'is_active', 'last_event_at',
    ];

    protected $casts = [
        'credentials_json' => 'encrypted:array',
        'is_active'        => 'boolean',
        'last_event_at'    => 'datetime',
    ];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    public static function hashSecret(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function verifySecret(string $signature, string $payload): bool
    {
        if ($this->webhook_secret_hash === null) return false;
        // HMAC-SHA256 verification — this is the realistic pattern;
        // scaffold just compares hashed secret for simplicity.
        $expected = hash_hmac('sha256', $payload, $this->rawSecret() ?? '');
        return hash_equals($expected, $signature);
    }

    // Scaffold holds only the hash; real implementations store encrypted raw
    // secret in credentials_json['webhook_secret']. Null when not wired.
    private function rawSecret(): ?string
    {
        return $this->credentials_json['webhook_secret'] ?? null;
    }

    public function scopeForTenant($q, int $tenantId) { return $q->where('tenant_id', $tenantId); }
    public function scopeActive($q)                   { return $q->where('is_active', true); }
}
