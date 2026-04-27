<?php

// ─── OAuthClient ──────────────────────────────────────────────────────────────
// Phase 11. Registered OAuth2 / SMART App Launch 2.0 client.
// Secret is stored SHA-256 hashed (like ApiToken) : plaintext shown once.
// Public clients (no secret) MUST use PKCE S256 at the /authorize endpoint.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OAuthClient extends Model
{
    protected $table = 'shared_oauth_clients';

    public const TYPES = ['confidential', 'public'];

    protected $fillable = [
        'tenant_id', 'client_id', 'client_secret_hash', 'name',
        'redirect_uris', 'client_type', 'allowed_scopes', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isPublic(): bool
    {
        return $this->client_type === 'public';
    }

    public function redirectUris(): array
    {
        return array_values(array_filter(explode('|', (string) $this->redirect_uris)));
    }

    public function allowedScopes(): array
    {
        return array_values(array_filter(explode('|', (string) $this->allowed_scopes)));
    }

    public function allowsRedirectUri(string $uri): bool
    {
        return in_array($uri, $this->redirectUris(), true);
    }

    public static function hashSecret(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function verifySecret(string $plaintext): bool
    {
        return $this->client_secret_hash !== null
            && hash_equals($this->client_secret_hash, self::hashSecret($plaintext));
    }

    public function scopeForTenant($q, int $tenantId)
    {
        return $q->where('tenant_id', $tenantId);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
