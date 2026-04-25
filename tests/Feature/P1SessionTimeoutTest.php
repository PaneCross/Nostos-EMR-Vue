<?php

// ─── Phase P1 — HIPAA-compliant session timeout ────────────────────────────
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class P1SessionTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_lifetime_committed_default_is_15(): void
    {
        // Verify config/session.php's committed default value (not whatever
        // env overrides set). Committed default must be HIPAA-compliant 15 min.
        $config = file_get_contents(config_path('session.php'));
        $this->assertMatchesRegularExpression(
            "/'lifetime'\\s*=>\\s*env\\('SESSION_LIFETIME',\\s*15\\)/",
            $config,
            'config/session.php committed lifetime default must be 15.'
        );
    }

    public function test_expire_on_close_committed_default_is_true(): void
    {
        $config = file_get_contents(config_path('session.php'));
        $this->assertMatchesRegularExpression(
            "/'expire_on_close'\\s*=>\\s*env\\('SESSION_EXPIRE_ON_CLOSE',\\s*true\\)/",
            $config,
            'config/session.php committed expire_on_close default must be true.'
        );
    }

    public function test_heartbeat_endpoint_responds_for_authenticated_user(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'primary_care',
            'role' => 'standard', 'is_active' => true,
        ]);
        $this->actingAs($u);
        $this->postJson('/auth/heartbeat')
            ->assertOk()
            ->assertJsonStructure(['ok', 'session_lifetime_minutes', 'expires_in_seconds']);
    }

    public function test_heartbeat_requires_authentication(): void
    {
        // Unauthenticated → web auth middleware redirects (302).
        $this->post('/auth/heartbeat')->assertRedirect('/login');
    }
}
