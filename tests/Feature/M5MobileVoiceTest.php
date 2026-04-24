<?php

// ─── Phase M5 — mobile + voice ─────────────────────────────────────────────
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class M5MobileVoiceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $dept = 'home_care'): User
    {
        $t = Tenant::factory()->create();
        return User::factory()->create([
            'tenant_id' => $t->id, 'department' => $dept,
            'role' => 'standard', 'is_active' => true,
        ]);
    }

    public function test_mobile_index_renders(): void
    {
        $this->actingAs($this->user());
        $this->get('/mobile')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Mobile/Index')->has('today'));
    }

    public function test_mobile_today_returns_json(): void
    {
        $this->actingAs($this->user());
        $this->getJson('/mobile/today')->assertOk()
            ->assertJsonStructure(['visits']);
    }

    public function test_wrong_dept_rejected(): void
    {
        $this->actingAs($this->user('dietary'));
        $this->get('/mobile')->assertForbidden();
    }
}
