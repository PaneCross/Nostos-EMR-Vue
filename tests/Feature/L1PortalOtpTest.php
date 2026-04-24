<?php

// ─── Phase L1 — Portal OTP auth ─────────────────────────────────────────────
namespace Tests\Feature;

use App\Models\OtpCode;
use App\Models\Participant;
use App\Models\ParticipantPortalUser;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class L1PortalOtpTest extends TestCase
{
    use RefreshDatabase;

    private ParticipantPortalUser $portalUser;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $tenant->id, 'mrn_prefix' => 'L1']);
        $p = Participant::factory()->enrolled()->forTenant($tenant->id)->forSite($site->id)->create();
        $this->portalUser = ParticipantPortalUser::create([
            'tenant_id' => $tenant->id, 'participant_id' => $p->id,
            'email' => 'otp@example.com', 'password' => Hash::make('x'),
            'is_active' => true,
        ]);
    }

    public function test_send_otp_creates_code_row(): void
    {
        RateLimiter::clear('portal_otp_send:otp@example.com');
        $this->postJson('/portal/otp/send', ['email' => 'otp@example.com'])
            ->assertOk();
        $this->assertEquals(1, OtpCode::where('participant_portal_user_id', $this->portalUser->id)->count());
    }

    public function test_verify_otp_with_correct_code_establishes_session(): void
    {
        RateLimiter::clear('portal_otp_send:otp@example.com');
        RateLimiter::clear('portal_otp_verify:otp@example.com');
        $this->postJson('/portal/otp/send', ['email' => 'otp@example.com'])->assertOk();

        // Grab the generated code — we only have the hash, so we issue a
        // direct row with a known code to test verification.
        OtpCode::where('participant_portal_user_id', $this->portalUser->id)->update(['used_at' => now()]);
        OtpCode::create([
            'participant_portal_user_id' => $this->portalUser->id,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1', 'attempts' => 0,
        ]);

        $r = $this->postJson('/portal/otp/verify', [
            'email' => 'otp@example.com', 'code' => '123456',
        ]);
        $r->assertOk();
        $this->assertEquals($this->portalUser->id, session('portal_user_id'));
    }

    public function test_verify_otp_with_wrong_code_returns_401(): void
    {
        RateLimiter::clear('portal_otp_verify:otp@example.com');
        OtpCode::create([
            'participant_portal_user_id' => $this->portalUser->id,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1', 'attempts' => 0,
        ]);
        $this->postJson('/portal/otp/verify', ['email' => 'otp@example.com', 'code' => '000000'])
            ->assertStatus(401);
    }

    public function test_send_otp_is_rate_limited(): void
    {
        RateLimiter::clear('portal_otp_send:otp@example.com');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/portal/otp/send', ['email' => 'otp@example.com'])->assertOk();
        }
        $this->postJson('/portal/otp/send', ['email' => 'otp@example.com'])
            ->assertStatus(429);
    }

    public function test_password_login_still_works(): void
    {
        RateLimiter::clear('portal_login:otp@example.com');
        $this->postJson('/portal/login', [
            'email' => 'otp@example.com', 'password' => 'x',
        ])->assertOk();
    }
}
