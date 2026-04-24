<?php

// ─── Phase K2 — BI report builder + dashboards UI ──────────────────────────
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class K2BiUiTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $t = Tenant::factory()->create();
        return User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'executive',
            'role' => 'admin', 'is_active' => true,
        ]);
    }

    public function test_builder_page_renders(): void
    {
        $this->actingAs($this->user());
        $this->get('/bi/builder')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Bi/ReportBuilder'));
    }

    public function test_dashboards_page_renders(): void
    {
        $this->actingAs($this->user());
        $this->get('/bi/saved')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Bi/Dashboards'));
    }

    public function test_dashboards_index_endpoint_responds(): void
    {
        $this->actingAs($this->user());
        $this->getJson('/bi/dashboards')->assertOk()
            ->assertJsonStructure(['dashboards']);
    }

    public function test_dashboard_create_roundtrip(): void
    {
        $u = $this->user();
        $this->actingAs($u);
        $r = $this->postJson('/bi/dashboards', [
            'title' => 'Test dashboard',
            'widgets' => [], // Phase O7: empty widgets allowed
            'is_shared' => false,
        ]);
        $r->assertStatus(201)->assertJsonStructure(['dashboard' => ['id', 'title']]);
    }
}
