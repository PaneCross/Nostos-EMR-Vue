<?php

// ─── OAuthAuthorizationCode ──────────────────────────────────────────────────
// Phase 11. Short-lived (60s) authorization code issued by /authorize, to be
// exchanged by the client at /token for an ApiToken row (the Bearer).
// Used-once : `used_at` is stamped on exchange and the row becomes dead.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OAuthAuthorizationCode extends Model
{
    protected $table = 'emr_oauth_authorization_codes';

    protected $fillable = [
        'tenant_id', 'oauth_client_id', 'user_id', 'participant_id',
        'code', 'scopes', 'redirect_uri',
        'code_challenge', 'code_challenge_method',
        'expires_at', 'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(OAuthClient::class, 'oauth_client_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopesArray(): array
    {
        return array_values(array_filter(explode('|', (string) $this->scopes)));
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function verifyPkce(?string $codeVerifier): bool
    {
        if ($this->code_challenge === null) return true; // PKCE not required
        if ($codeVerifier === null) return false;
        if ($this->code_challenge_method !== 'S256') return false; // only S256 accepted
        $hash = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        return hash_equals($this->code_challenge, $hash);
    }
}
