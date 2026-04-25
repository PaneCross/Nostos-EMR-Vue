<?php

// ─── Phase U2 — Notifications route accepts both PUT and PATCH ─────────────
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class U2NotificationsRouteTest extends TestCase
{
    use RefreshDatabase;

    private function authedUser(): User
    {
        $t = Tenant::factory()->create();
        return User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);
    }

    public function test_put_notifications_succeeds(): void
    {
        $u = $this->authedUser();
        $this->actingAs($u)
            ->putJson('/profile/notifications', [
                'preferences' => ['alert_critical' => 'email_immediate'],
            ])
            ->assertOk();
    }

    public function test_patch_notifications_also_succeeds(): void
    {
        // Audit-9 H7-1: frontend used PATCH; route was PUT-only and silently 405'd.
        $u = $this->authedUser();
        $this->actingAs($u)
            ->patchJson('/profile/notifications', [
                'preferences' => ['alert_critical' => 'email_immediate'],
            ])
            ->assertOk();
    }

    public function test_axios_interceptor_present_in_app_entry(): void
    {
        $entry = file_get_contents(resource_path('js/app.ts'));
        $this->assertStringContainsString('axios.interceptors.response.use', $entry);
        $this->assertStringContainsString('Phase U2', $entry);
    }
}
