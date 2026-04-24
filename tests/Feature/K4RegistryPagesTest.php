<?php

// ─── Phase K4 — Disease registry Vue pages ─────────────────────────────────
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class K4RegistryPagesTest extends TestCase
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

    public function test_diabetes_page_renders(): void
    {
        $this->actingAs($this->user());
        // Phase O3: canonical URL is /registries/{r} (dual-serves JSON+Inertia)
        $this->get('/registries/diabetes')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Registries/Diabetes'));
    }

    public function test_chf_page_renders(): void
    {
        $this->actingAs($this->user());
        $this->get('/registries/chf')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Registries/Chf'));
    }

    public function test_copd_page_renders(): void
    {
        $this->actingAs($this->user());
        $this->get('/registries/copd')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Registries/Copd'));
    }

    public function test_registry_data_endpoint(): void
    {
        $this->actingAs($this->user());
        $this->getJson('/registries/diabetes')->assertOk()
            ->assertJsonStructure(['label', 'count', 'rows']);
    }
}
