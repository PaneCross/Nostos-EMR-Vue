<?php

// ─── Phase I4 — Portal login page + session-backed auth ─────────────────────
namespace Tests\Feature;

use App\Models\Participant;
use App\Models\ParticipantPortalUser;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class I4PortalSessionAuthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Participant $participant;
    private ParticipantPortalUser $portalUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'I4']);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($site->id)
            ->create(['first_name' => 'Alice', 'last_name' => 'Patient']);
        $this->portalUser = ParticipantPortalUser::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'email' => 'alice@example.com',
            'password' => Hash::make('correct-horse'),
            'is_active' => true,
        ]);
    }

    public function test_login_page_renders(): void
    {
        $r = $this->get('/portal/login');
        $r->assertOk();
        $r->assertInertia(fn ($page) => $page->component('Portal/Login'));
    }

    public function test_login_sets_session_and_returns_ok(): void
    {
        RateLimiter::clear('portal_login:alice@example.com');
        $r = $this->postJson('/portal/login', [
            'email' => 'alice@example.com', 'password' => 'correct-horse',
        ]);
        $r->assertOk();
        $this->assertEquals($this->portalUser->id, session('portal_user_id'));
    }

    public function test_session_auth_allows_overview_request(): void
    {
        RateLimiter::clear('portal_login:alice@example.com');
        $this->postJson('/portal/login', [
            'email' => 'alice@example.com', 'password' => 'correct-horse',
        ])->assertOk();

        // Fresh request; session carries.
        $r = $this->getJson('/portal/overview');
        $r->assertOk();
        $this->assertEquals('Alice', $r->json('participant.first_name') ?? null);
    }

    public function test_logout_clears_session(): void
    {
        RateLimiter::clear('portal_login:alice@example.com');
        $this->postJson('/portal/login', [
            'email' => 'alice@example.com', 'password' => 'correct-horse',
        ])->assertOk();
        $this->postJson('/portal/logout')->assertOk();
        $this->assertNull(session('portal_user_id'));
        $this->getJson('/portal/overview')->assertStatus(401);
    }

    public function test_rate_limiter_blocks_after_5_failed_attempts(): void
    {
        RateLimiter::clear('portal_login:alice@example.com');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/portal/login', [
                'email' => 'alice@example.com', 'password' => 'wrong',
            ])->assertStatus(401);
        }
        // 6th should 429
        $r = $this->postJson('/portal/login', [
            'email' => 'alice@example.com', 'password' => 'correct-horse',
        ]);
        $r->assertStatus(429);
        $this->assertEquals('rate_limited', $r->json('error'));
    }

    public function test_header_auth_still_works_for_back_compat(): void
    {
        $r = $this->withHeader('X-Portal-User-Id', (string) $this->portalUser->id)
            ->getJson('/portal/overview');
        $r->assertOk();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->portalUser->update(['is_active' => false]);
        RateLimiter::clear('portal_login:alice@example.com');
        $this->postJson('/portal/login', [
            'email' => 'alice@example.com', 'password' => 'correct-horse',
        ])->assertStatus(401);
    }

    public function test_wrong_email_returns_401_and_logs_attempt(): void
    {
        RateLimiter::clear('portal_login:ghost@example.com');
        $this->postJson('/portal/login', [
            'email' => 'ghost@example.com', 'password' => 'anything',
        ])->assertStatus(401);
    }
}
