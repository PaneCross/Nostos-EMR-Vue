<?php

// ─── Phase K3 — Operational Inertia pages ──────────────────────────────────
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class K3OperationalPagesTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $t = Tenant::factory()->create();
        return User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);
    }

    public function test_panel_page_renders(): void
    {
        $this->actingAs($this->user());
        $this->get('/ops/panel')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Operations/Panel'));
    }

    public function test_dietary_page_renders(): void
    {
        $this->actingAs($this->user());
        $this->get('/ops/dietary')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Operations/DietaryOrders'));
    }

    public function test_activities_page_renders(): void
    {
        $this->actingAs($this->user());
        $this->get('/ops/activities')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Operations/ActivitiesCalendar'));
    }

    public function test_huddle_page_renders(): void
    {
        $this->actingAs($this->user());
        $this->get('/ops/huddle')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Operations/Huddle'));
    }
}
