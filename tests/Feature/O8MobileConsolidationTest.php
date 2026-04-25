<?php

// ─── Phase O8 — /home-care/mobile-adl redirects to /mobile ────────────────
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class O8MobileConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_route_redirects(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'home_care',
            'role' => 'standard', 'is_active' => true,
        ]);
        $this->actingAs($u);
        $this->get('/home-care/mobile-adl')->assertRedirect('/mobile');
    }

    public function test_canonical_mobile_page_renders(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'home_care',
            'role' => 'standard', 'is_active' => true,
        ]);
        $this->actingAs($u);
        $this->get('/mobile')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Mobile/Index'));
    }

    public function test_old_mobile_adl_page_file_deleted(): void
    {
        $this->assertFileDoesNotExist(
            resource_path('js/Pages/HomeCare/MobileAdl.vue')
        );
    }
}
