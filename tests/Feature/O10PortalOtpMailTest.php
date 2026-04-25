<?php

// ─── Phase O10 — Portal OTP email delivery via PortalOtpMail ───────────────
namespace Tests\Feature;

use App\Mail\PortalOtpMail;
use App\Models\Participant;
use App\Models\ParticipantPortalUser;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class O10PortalOtpMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_otp_dispatches_portal_otp_mail(): void
    {
        Mail::fake();
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'O10']);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        $portalUser = ParticipantPortalUser::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'email' => 'o10@example.com', 'password' => Hash::make('x'),
            'is_active' => true,
        ]);
        RateLimiter::clear('portal_otp_send:o10@example.com');

        $this->postJson('/portal/otp/send', ['email' => 'o10@example.com'])
            ->assertOk();

        Mail::assertSent(PortalOtpMail::class, fn (PortalOtpMail $m) =>
            $m->user->id === $portalUser->id
            && $m->hasTo('o10@example.com')
            && strlen($m->code) === 6
        );
    }

    public function test_send_otp_with_unknown_email_does_not_dispatch_mail(): void
    {
        Mail::fake();
        RateLimiter::clear('portal_otp_send:unknown@example.com');
        $this->postJson('/portal/otp/send', ['email' => 'unknown@example.com'])
            ->assertOk();
        Mail::assertNothingSent();
    }

    public function test_blade_view_renders_with_code_placeholder(): void
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'O10']);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        $portalUser = ParticipantPortalUser::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'email' => 'view@example.com', 'password' => Hash::make('x'),
            'is_active' => true,
        ]);
        $rendered = (new PortalOtpMail($portalUser, '482917'))->render();
        $this->assertStringContainsString('482917', $rendered);
        $this->assertStringContainsString('Participant Portal', $rendered);
    }
}
