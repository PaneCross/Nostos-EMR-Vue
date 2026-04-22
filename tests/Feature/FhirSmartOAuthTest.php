<?php

// ─── FhirSmartOAuthTest ───────────────────────────────────────────────────────
// Phase 11 (MVP roadmap). Covers the SMART App Launch 2.0 additions:
//   - CapabilityStatement + .well-known/smart-configuration
//   - /authorize + /token (authorization_code + PKCE S256)
//   - /token (client_credentials)
//   - /introspect, /revoke
//   - SMART scope notation enforcement on FHIR endpoints
//   - Developer docs page reachable
// 15 tests minimum per roadmap DoD.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\OAuthAuthorizationCode;
use App\Models\OAuthClient;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FhirSmartOAuthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $user;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'SMA']);
        $this->user   = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'it_admin',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();
    }

    // ── Discovery endpoints ─────────────────────────────────────────────────

    public function test_metadata_endpoint_returns_capability_statement(): void
    {
        $r = $this->getJson('/fhir/R4/metadata');
        $r->assertOk();
        $r->assertHeader('Content-Type', 'application/fhir+json');
        $r->assertJsonPath('resourceType', 'CapabilityStatement');
        $r->assertJsonPath('fhirVersion', '4.0.1');
        $r->assertJsonPath('rest.0.security.service.0.coding.0.code', 'SMART-on-FHIR');
    }

    public function test_metadata_lists_all_supported_resources(): void
    {
        $r = $this->getJson('/fhir/R4/metadata')->json();
        $types = collect($r['rest'][0]['resource'])->pluck('type')->all();
        foreach (['Patient', 'Observation', 'MedicationRequest', 'Condition', 'AllergyIntolerance',
                  'CarePlan', 'Appointment', 'Immunization', 'Procedure', 'Encounter',
                  'DiagnosticReport', 'Practitioner', 'Organization'] as $t) {
            $this->assertContains($t, $types, "CapabilityStatement missing {$t}");
        }
    }

    public function test_smart_well_known_configuration(): void
    {
        $r = $this->getJson('/fhir/R4/.well-known/smart-configuration');
        $r->assertOk();
        $r->assertJsonPath('code_challenge_methods_supported.0', 'S256');
        $data = $r->json();
        $this->assertStringContainsString('/fhir/R4/auth/authorize', $data['authorization_endpoint']);
        $this->assertStringContainsString('/fhir/R4/auth/token', $data['token_endpoint']);
        $this->assertContains('authorization_code', $data['grant_types_supported']);
        $this->assertContains('client_credentials', $data['grant_types_supported']);
    }

    public function test_developer_docs_page_reachable(): void
    {
        $this->get('/fhir/R4/docs')
            ->assertOk()
            ->assertSee('NostosEMR FHIR R4 API', false);
    }

    // ── SMART scope enforcement on FHIR endpoint ────────────────────────────

    public function test_smart_wildcard_scope_satisfies_legacy_scope_check(): void
    {
        $plaintext = Str::random(64);
        ApiToken::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->user->id,
            'token'     => ApiToken::hashToken($plaintext),
            'scopes'    => ['system/*.read'],
            'name'      => 'wildcard test',
        ]);

        $this->get("/fhir/R4/Patient/{$this->participant->id}", ['Authorization' => 'Bearer ' . $plaintext])
            ->assertOk()
            ->assertJsonPath('resourceType', 'Patient');
    }

    public function test_smart_resource_specific_scope_satisfies_legacy_check(): void
    {
        $plaintext = Str::random(64);
        ApiToken::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->user->id,
            'token'     => ApiToken::hashToken($plaintext),
            'scopes'    => ['patient/Patient.read'],
            'name'      => 'smart specific',
        ]);

        $this->get("/fhir/R4/Patient/{$this->participant->id}", ['Authorization' => 'Bearer ' . $plaintext])
            ->assertOk();
    }

    public function test_smart_scope_does_not_grant_other_resources(): void
    {
        $plaintext = Str::random(64);
        ApiToken::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->user->id,
            'token'     => ApiToken::hashToken($plaintext),
            'scopes'    => ['patient/Patient.read'],
            'name'      => 'narrow',
        ]);

        // Should NOT grant Observation access
        $this->get("/fhir/R4/Observation?patient={$this->participant->id}", ['Authorization' => 'Bearer ' . $plaintext])
            ->assertStatus(403);
    }

    // ── /authorize ──────────────────────────────────────────────────────────

    public function test_authorize_redirects_guest_to_login(): void
    {
        $this->get('/fhir/R4/auth/authorize?response_type=code&client_id=x&redirect_uri=https://app.example/cb&scope=patient/Patient.read&state=abc')
            ->assertRedirect('/login');
    }

    public function test_authorize_issues_code_and_redirects_to_registered_uri(): void
    {
        $client = $this->makePublicClient(['patient/Patient.read']);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', 'verifier-abc', true)), '+/', '-_'), '=');

        $r = $this->actingAs($this->user)->get('/fhir/R4/auth/authorize?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => $client->client_id,
            'redirect_uri'          => 'https://app.example/cb',
            'scope'                 => 'patient/Patient.read',
            'state'                 => 'xyz123',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]));

        $r->assertStatus(302);
        $this->assertStringStartsWith('https://app.example/cb?', $r->headers->get('Location'));
        $this->assertStringContainsString('state=xyz123', $r->headers->get('Location'));
        $this->assertDatabaseCount('emr_oauth_authorization_codes', 1);
    }

    public function test_authorize_rejects_unregistered_redirect_uri(): void
    {
        $client = $this->makePublicClient(['patient/Patient.read']);
        $this->actingAs($this->user)
            ->get('/fhir/R4/auth/authorize?' . http_build_query([
                'response_type' => 'code',
                'client_id'     => $client->client_id,
                'redirect_uri'  => 'https://evil.example/cb',
                'scope'         => 'patient/Patient.read',
                'state'         => 'xyz',
                'code_challenge'=> 'x', 'code_challenge_method' => 'S256',
            ]))
            ->assertStatus(400);
    }

    public function test_authorize_requires_pkce_for_public_clients(): void
    {
        $client = $this->makePublicClient(['patient/Patient.read']);
        $r = $this->actingAs($this->user)
            ->get('/fhir/R4/auth/authorize?' . http_build_query([
                'response_type' => 'code',
                'client_id'     => $client->client_id,
                'redirect_uri'  => 'https://app.example/cb',
                'scope'         => 'patient/Patient.read',
                'state'         => 'xyz',
            ]));
        $r->assertStatus(302);
        $this->assertStringContainsString('error=invalid_request', $r->headers->get('Location'));
    }

    // ── /token authorization_code ───────────────────────────────────────────

    public function test_token_exchanges_code_for_bearer(): void
    {
        $client = $this->makePublicClient(['patient/Patient.read', 'patient/Observation.read']);
        $verifier = 'verifier-abc';
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->actingAs($this->user)->get('/fhir/R4/auth/authorize?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => $client->client_id,
            'redirect_uri'          => 'https://app.example/cb',
            'scope'                 => 'patient/Patient.read patient/Observation.read',
            'state'                 => 's',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]));

        $code = OAuthAuthorizationCode::first()->code;

        $r = $this->post('/fhir/R4/auth/token', [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => 'https://app.example/cb',
            'client_id'     => $client->client_id,
            'code_verifier' => $verifier,
        ]);

        $r->assertOk();
        $this->assertNotEmpty($r->json('access_token'));
        $this->assertEquals('Bearer', $r->json('token_type'));
        $this->assertEquals(3600, $r->json('expires_in'));
        $this->assertStringContainsString('patient/Patient.read', $r->json('scope'));
    }

    public function test_token_rejects_wrong_pkce_verifier(): void
    {
        $client = $this->makePublicClient(['patient/Patient.read']);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', 'right-verifier', true)), '+/', '-_'), '=');

        $this->actingAs($this->user)->get('/fhir/R4/auth/authorize?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => $client->client_id,
            'redirect_uri'          => 'https://app.example/cb',
            'scope'                 => 'patient/Patient.read',
            'state'                 => 's',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]));
        $code = OAuthAuthorizationCode::first()->code;

        $r = $this->post('/fhir/R4/auth/token', [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => 'https://app.example/cb',
            'client_id'     => $client->client_id,
            'code_verifier' => 'WRONG',
        ]);

        $r->assertStatus(400);
        $this->assertEquals('invalid_grant', $r->json('error'));
    }

    public function test_token_rejects_reused_authorization_code(): void
    {
        $client = $this->makePublicClient(['patient/Patient.read']);
        $verifier = 'v';
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->actingAs($this->user)->get('/fhir/R4/auth/authorize?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => $client->client_id,
            'redirect_uri'          => 'https://app.example/cb',
            'scope'                 => 'patient/Patient.read',
            'state'                 => 's',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]));
        $code = OAuthAuthorizationCode::first()->code;

        $first = $this->post('/fhir/R4/auth/token', [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => 'https://app.example/cb',
            'client_id'     => $client->client_id,
            'code_verifier' => $verifier,
        ]);
        $first->assertOk();

        $second = $this->post('/fhir/R4/auth/token', [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => 'https://app.example/cb',
            'client_id'     => $client->client_id,
            'code_verifier' => $verifier,
        ]);
        $second->assertStatus(400);
        $this->assertEquals('invalid_grant', $second->json('error'));
    }

    // ── /token client_credentials ───────────────────────────────────────────

    public function test_token_client_credentials_issues_bearer(): void
    {
        $secret = 'super-secret-xyz';
        $client = OAuthClient::create([
            'tenant_id'          => $this->tenant->id,
            'client_id'          => 'svc-' . Str::random(8),
            'client_secret_hash' => OAuthClient::hashSecret($secret),
            'name'               => 'Backend service',
            'redirect_uris'      => '',
            'client_type'        => 'confidential',
            'allowed_scopes'     => 'system/Patient.read|system/Observation.read',
            'is_active'          => true,
        ]);

        $r = $this->post('/fhir/R4/auth/token', [
            'grant_type' => 'client_credentials',
            'scope'      => 'system/Patient.read',
        ], ['Authorization' => 'Basic ' . base64_encode($client->client_id . ':' . $secret)]);

        $r->assertOk();
        $this->assertNotEmpty($r->json('access_token'));
    }

    public function test_token_client_credentials_rejects_bad_secret(): void
    {
        $client = OAuthClient::create([
            'tenant_id'          => $this->tenant->id,
            'client_id'          => 'svc-' . Str::random(8),
            'client_secret_hash' => OAuthClient::hashSecret('correct'),
            'name'               => 'Backend',
            'client_type'        => 'confidential',
            'allowed_scopes'     => 'system/Patient.read',
            'is_active'          => true,
        ]);

        $r = $this->post('/fhir/R4/auth/token', [
            'grant_type' => 'client_credentials',
        ], ['Authorization' => 'Basic ' . base64_encode($client->client_id . ':WRONG')]);

        $r->assertStatus(401);
    }

    // ── /introspect and /revoke ─────────────────────────────────────────────

    public function test_introspect_returns_active_true_for_valid_token(): void
    {
        $plaintext = Str::random(64);
        ApiToken::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->user->id,
            'token'     => ApiToken::hashToken($plaintext),
            'scopes'    => ['patient/Patient.read'],
            'name'      => 'introspect test',
        ]);

        $r = $this->post('/fhir/R4/auth/introspect', ['token' => $plaintext]);
        $r->assertOk();
        $this->assertTrue($r->json('active'));
    }

    public function test_introspect_returns_active_false_for_invalid_token(): void
    {
        $r = $this->post('/fhir/R4/auth/introspect', ['token' => 'totally-bogus']);
        $r->assertOk();
        $this->assertFalse($r->json('active'));
    }

    public function test_revoke_deletes_token_and_returns_200(): void
    {
        $plaintext = Str::random(64);
        $t = ApiToken::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->user->id,
            'token'     => ApiToken::hashToken($plaintext),
            'scopes'    => ['patient/Patient.read'],
            'name'      => 'revoke test',
        ]);
        $id = $t->id;

        $r = $this->post('/fhir/R4/auth/revoke', ['token' => $plaintext]);
        $r->assertOk();
        $this->assertDatabaseMissing('emr_api_tokens', ['id' => $id]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function makePublicClient(array $scopes): OAuthClient
    {
        return OAuthClient::create([
            'tenant_id'          => $this->tenant->id,
            'client_id'          => 'app-' . Str::random(8),
            'client_secret_hash' => null,
            'name'               => 'Public Test App',
            'redirect_uris'      => 'https://app.example/cb',
            'client_type'        => 'public',
            'allowed_scopes'     => implode('|', $scopes),
            'is_active'          => true,
        ]);
    }
}
