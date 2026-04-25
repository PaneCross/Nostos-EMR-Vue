<?php

// ─── Phase Q4 — Eligibility chip endpoint + IT-admin config visibility ─────
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Q4EligibilityChipAndConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_integrations_page_includes_eligibility_config_block(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'it_admin',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($u);
        config(['services.eligibility.driver' => 'null']);

        $this->get('/it-admin/integrations')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('ItAdmin/Integrations')
                ->has('eligibility', fn ($e) => $e
                    ->where('driver', 'null')
                    ->where('is_real_vendor', false)
                    ->etc()
                )
            );
    }

    public function test_integrations_page_marks_real_vendor_when_driver_set(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'it_admin',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($u);
        config(['services.eligibility.driver' => 'availity']);

        $this->get('/it-admin/integrations')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('eligibility.driver', 'availity')
                ->where('eligibility.is_real_vendor', true)
            );
    }
}
