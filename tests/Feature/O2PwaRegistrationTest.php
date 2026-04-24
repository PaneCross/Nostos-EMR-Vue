<?php

// ─── Phase O2 — PWA manifest + service worker registration ─────────────────
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class O2PwaRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_link_present_in_rendered_shell(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($u);
        $r = $this->get('/dashboard/primary_care');
        $r->assertOk();
        $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $r->getContent());
        $this->assertStringContainsString('name="theme-color"', $r->getContent());
    }

    public function test_service_worker_registration_is_in_app_entry(): void
    {
        $js = file_get_contents(resource_path('js/app.ts'));
        $this->assertStringContainsString("navigator.serviceWorker.register('/sw.js'", $js);
    }

    public function test_sw_cache_version_is_bumped(): void
    {
        $sw = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("nostos-portal-v2", $sw);
    }
}
