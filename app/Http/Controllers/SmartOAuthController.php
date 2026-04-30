<?php

// ─── SmartOAuthController ────────────────────────────────────────────────────
// Phase 11. Lightweight SMART App Launch 2.0 / OAuth2 implementation built on
// top of our existing emr_api_tokens infrastructure. No Laravel Passport.
//
// Endpoints:
//   GET  /fhir/R4/auth/authorize   → SMART standalone-launch consent flow
//                                    (requires logged-in EMR user)
//   POST /fhir/R4/auth/token       → exchange code (auth_code grant) or
//                                    client_credentials for a Bearer token
//   POST /fhir/R4/auth/introspect  → RFC 7662 token introspection
//   POST /fhir/R4/auth/revoke      → RFC 7009 token revocation
//
// Grants supported:
//   - authorization_code (with PKCE S256 required for public clients)
//   - client_credentials (backend-to-backend, system/*.read scopes)
//
// Scopes: any subset of the client's `allowed_scopes`. Legacy dot-scopes and
// SMART-style scopes both accepted on input; stored verbatim, enforced via
// ApiToken::hasScope() which understands both notations.
//
// Deliberate omissions (for the MVP, not forever):
//   - refresh_token grant (SMART "offline_access" scope) : defer to Phase 15
//   - OpenID id_token : defer to Phase 15
//   - launch (EHR-embedded) : defer; standalone launch covers MVP use cases
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\OAuthAuthorizationCode;
use App\Models\OAuthClient;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SmartOAuthController extends Controller
{
    // ── /authorize ──────────────────────────────────────────────────────────

    public function authorize(Request $request)
    {
        $u = Auth::user();
        if (! $u) {
            return redirect()->guest('/login')
                ->with('info', 'Log in to authorize the third-party app requesting access.');
        }

        $validated = $request->validate([
            'response_type'         => 'required|in:code',
            'client_id'             => 'required|string',
            'redirect_uri'          => 'required|url',
            'scope'                 => 'required|string',
            'state'                 => 'required|string|max:512',
            'aud'                   => 'nullable|string',
            'code_challenge'        => 'nullable|string|max:128',
            'code_challenge_method' => 'nullable|in:S256',
            'launch'                => 'nullable|string', // opaque launch context (unused MVP)
            'patient'               => 'nullable|integer', // standalone "select patient" shortcut
        ]);

        $client = OAuthClient::forTenant($u->effectiveTenantId())
            ->active()
            ->where('client_id', $validated['client_id'])
            ->first();

        if (! $client) {
            return $this->oauthError($validated['redirect_uri'] ?? null, $validated['state'] ?? null,
                'invalid_client', 'Unknown or inactive client_id.');
        }

        if (! $client->allowsRedirectUri($validated['redirect_uri'])) {
            // Do NOT redirect : per OAuth2 spec, bad redirect_uri must not redirect
            return response()->json([
                'error'             => 'invalid_redirect_uri',
                'error_description' => 'redirect_uri is not registered for this client.',
            ], 400);
        }

        // PKCE required for public clients
        if ($client->isPublic()) {
            if (empty($validated['code_challenge']) || ($validated['code_challenge_method'] ?? null) !== 'S256') {
                return $this->oauthError($validated['redirect_uri'], $validated['state'],
                    'invalid_request', 'Public clients must use PKCE with S256.');
            }
        }

        // Scope must be subset of allowed_scopes
        $requested = array_values(array_filter(preg_split('/\s+/', $validated['scope']) ?: []));
        $allowed   = $client->allowedScopes();
        foreach ($requested as $s) {
            if (! in_array($s, $allowed, true)) {
                return $this->oauthError($validated['redirect_uri'], $validated['state'],
                    'invalid_scope', "Scope {$s} not permitted for this client.");
            }
        }

        // Mint authorization code
        $code = Str::random(96);
        $row = OAuthAuthorizationCode::create([
            'tenant_id'             => $u->effectiveTenantId(),
            'oauth_client_id'       => $client->id,
            'user_id'               => $u->id,
            'participant_id'        => $validated['patient'] ?? null,
            'code'                  => $code,
            'scopes'                => implode('|', $requested),
            'redirect_uri'          => $validated['redirect_uri'],
            'code_challenge'        => $validated['code_challenge'] ?? null,
            'code_challenge_method' => $validated['code_challenge_method'] ?? null,
            'expires_at'            => now()->addSeconds(60),
        ]);

        AuditLog::record(
            action: 'fhir.oauth_code_issued',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'oauth_client',
            resourceId: $client->id,
            description: "SMART authorize: client={$client->client_id} scopes=" . implode(' ', $requested),
        );

        $sep = str_contains($validated['redirect_uri'], '?') ? '&' : '?';
        $location = $validated['redirect_uri']
            . $sep . 'code=' . urlencode($code)
            . '&state=' . urlencode($validated['state']);

        return redirect()->away($location);
    }

    // ── /token ──────────────────────────────────────────────────────────────

    public function token(Request $request): JsonResponse
    {
        $grantType = (string) $request->input('grant_type', '');

        return match ($grantType) {
            'authorization_code' => $this->exchangeAuthorizationCode($request),
            'client_credentials' => $this->issueClientCredentials($request),
            default              => response()->json([
                'error'             => 'unsupported_grant_type',
                'error_description' => "grant_type '{$grantType}' is not supported.",
            ], 400),
        };
    }

    private function exchangeAuthorizationCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'          => 'required|string',
            'redirect_uri'  => 'required|url',
            'client_id'     => 'required|string',
            'code_verifier' => 'nullable|string',
            'client_secret' => 'nullable|string',
        ]);

        // Client authentication (Basic header or posted body)
        [$clientId, $clientSecret] = $this->resolveClientCredentials($request, $validated);

        return DB::transaction(function () use ($validated, $clientId, $clientSecret) {
            $row = OAuthAuthorizationCode::where('code', $validated['code'])->lockForUpdate()->first();

            if (! $row || $row->isExpired() || $row->isUsed()) {
                return response()->json([
                    'error' => 'invalid_grant',
                    'error_description' => 'Authorization code invalid, expired, or already used.',
                ], 400);
            }

            if ($row->redirect_uri !== $validated['redirect_uri']) {
                return response()->json([
                    'error' => 'invalid_grant',
                    'error_description' => 'redirect_uri mismatch.',
                ], 400);
            }

            $client = $row->client;
            if (! $client || ! $client->is_active || $client->client_id !== $clientId) {
                return response()->json([
                    'error' => 'invalid_client',
                    'error_description' => 'Unknown or mismatched client.',
                ], 401);
            }

            if (! $client->isPublic()) {
                if (! $clientSecret || ! $client->verifySecret($clientSecret)) {
                    return response()->json([
                        'error' => 'invalid_client',
                        'error_description' => 'Client authentication failed.',
                    ], 401);
                }
            }

            // PKCE
            if ($row->code_challenge !== null && ! $row->verifyPkce($validated['code_verifier'] ?? null)) {
                return response()->json([
                    'error' => 'invalid_grant',
                    'error_description' => 'PKCE verification failed.',
                ], 400);
            }

            $row->update(['used_at' => now()]);

            $scopes = $row->scopesArray();
            $plaintext = Str::random(64);
            $token = ApiToken::create([
                'user_id'    => $row->user_id,
                'tenant_id'  => $row->tenant_id,
                'token'      => ApiToken::hashToken($plaintext),
                'scopes'     => $scopes,
                'name'       => 'SMART: ' . $client->name,
                'expires_at' => now()->addHour(),
            ]);

            AuditLog::record(
                action: 'fhir.oauth_token_issued',
                tenantId: $row->tenant_id,
                userId: $row->user_id,
                resourceType: 'oauth_client',
                resourceId: $client->id,
                description: 'SMART token issued: scopes=' . implode(' ', $scopes),
            );

            $body = [
                'access_token' => $plaintext,
                'token_type'   => 'Bearer',
                'expires_in'   => 3600,
                'scope'        => implode(' ', $scopes),
            ];
            if ($row->participant_id) {
                $body['patient'] = (string) $row->participant_id;
            }

            return response()->json($body, 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        });
    }

    private function issueClientCredentials(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope'         => 'nullable|string',
            'client_id'     => 'nullable|string',
            'client_secret' => 'nullable|string',
        ]);

        [$clientId, $clientSecret] = $this->resolveClientCredentials($request, $validated);

        if (! $clientId || ! $clientSecret) {
            return response()->json(['error' => 'invalid_client', 'error_description' => 'Client credentials required.'], 401);
        }

        $client = OAuthClient::active()->where('client_id', $clientId)->first();
        if (! $client || $client->isPublic() || ! $client->verifySecret($clientSecret)) {
            return response()->json(['error' => 'invalid_client', 'error_description' => 'Client authentication failed.'], 401);
        }

        $requested = $validated['scope']
            ? array_values(array_filter(preg_split('/\s+/', $validated['scope']) ?: []))
            : $client->allowedScopes();

        foreach ($requested as $s) {
            if (! in_array($s, $client->allowedScopes(), true)) {
                return response()->json(['error' => 'invalid_scope', 'error_description' => "Scope {$s} not permitted."], 400);
            }
        }

        $plaintext = Str::random(64);
        $token = ApiToken::create([
            'user_id'    => null, // system-issued; no human user
            'tenant_id'  => $client->tenant_id,
            'token'      => ApiToken::hashToken($plaintext),
            'scopes'     => $requested,
            'name'       => 'SMART client_credentials: ' . $client->name,
            'expires_at' => now()->addHour(),
        ]);

        AuditLog::record(
            action: 'fhir.oauth_token_issued',
            tenantId: $client->tenant_id,
            userId: null,
            resourceType: 'oauth_client',
            resourceId: $client->id,
            description: 'SMART client_credentials token issued: scopes=' . implode(' ', $requested),
        );

        return response()->json([
            'access_token' => $plaintext,
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
            'scope'        => implode(' ', $requested),
        ], 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    // ── /introspect ─────────────────────────────────────────────────────────

    public function introspect(Request $request): JsonResponse
    {
        $plaintext = (string) $request->input('token', '');
        if ($plaintext === '') {
            return response()->json(['active' => false]);
        }
        $token = ApiToken::findByToken($plaintext);
        if (! $token) {
            return response()->json(['active' => false]);
        }
        return response()->json([
            'active'    => true,
            'scope'     => implode(' ', (array) $token->scopes),
            'client_id' => null,
            'tenant_id' => $token->tenant_id,
            'user_id'   => $token->user_id,
            'exp'       => $token->expires_at?->timestamp,
        ]);
    }

    // ── /revoke ─────────────────────────────────────────────────────────────

    public function revoke(Request $request): JsonResponse
    {
        $plaintext = (string) $request->input('token', '');
        if ($plaintext === '') {
            return response()->json([], 200);
        }
        $token = ApiToken::findByToken($plaintext);
        if ($token) {
            AuditLog::record(
                action: 'fhir.oauth_token_revoked',
                tenantId: $token->tenant_id,
                userId: $token->user_id,
                resourceType: 'api_token',
                resourceId: $token->id,
                description: 'SMART token revoked',
            );
            $token->delete();
        }
        // RFC 7009: always 200 regardless of whether the token existed
        return response()->json([], 200);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function resolveClientCredentials(Request $request, array $validated): array
    {
        // Basic auth header
        $authHeader = (string) $request->header('Authorization', '');
        if (str_starts_with($authHeader, 'Basic ')) {
            $raw = base64_decode(substr($authHeader, 6), true);
            if ($raw !== false && str_contains($raw, ':')) {
                [$id, $secret] = explode(':', $raw, 2);
                return [$id, $secret];
            }
        }
        return [
            $validated['client_id']     ?? null,
            $validated['client_secret'] ?? null,
        ];
    }

    private function oauthError(?string $redirectUri, ?string $state, string $code, string $desc)
    {
        if ($redirectUri) {
            $sep = str_contains($redirectUri, '?') ? '&' : '?';
            return redirect()->away($redirectUri
                . $sep . 'error=' . urlencode($code)
                . '&error_description=' . urlencode($desc)
                . ($state ? '&state=' . urlencode($state) : ''));
        }
        return response()->json(['error' => $code, 'error_description' => $desc], 400);
    }
}
