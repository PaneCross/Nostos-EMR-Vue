<?php

// ─── Phase O7 — empty-widgets allowed on saved dashboards ──────────────────
namespace Tests\Feature;

use App\Models\SavedDashboard;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class O7BiValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_widgets_accepted(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'executive', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($u);

        $r = $this->postJson('/bi/dashboards', [
            'title' => 'Empty BI dashboard',
            'widgets' => [],
            'is_shared' => false,
        ]);
        $r->assertStatus(201);
        $d = SavedDashboard::first();
        $this->assertEquals([], $d->widgets);
    }

    public function test_missing_widgets_key_still_rejected(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'executive', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($u);
        // 'present' requires the key to be in the payload
        $this->postJson('/bi/dashboards', [
            'title' => 'No widgets key',
            'is_shared' => false,
        ])->assertStatus(422);
    }
}
