<?php

// ─── Phase Q5 — Prior auth auto-suggest on prescribe ───────────────────────
namespace Tests\Feature;

use App\Models\FormularyEntry;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Q5PriorAuthAutoSuggestTest extends TestCase
{
    use RefreshDatabase;

    private function setupRx(): array
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'PA']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'primary_care', 'role' => 'standard', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        return [$t, $u, $p];
    }

    public function test_pa_required_drug_returns_pa_suggestion(): void
    {
        [$t, $u, $p] = $this->setupRx();
        FormularyEntry::create([
            'tenant_id' => $t->id, 'rxnorm_code' => '197381',
            'drug_name' => 'Eliquis', 'generic_name' => 'apixaban',
            'tier' => 3, 'prior_authorization_required' => true,
            'is_active' => true,
        ]);

        $r = $this->actingAs($u)
            ->postJson("/participants/{$p->id}/medications", [
                'drug_name' => 'Eliquis', 'dose' => 5, 'dose_unit' => 'mg',
                'route' => 'oral', 'frequency' => 'daily',
                'start_date' => now()->toDateString(), 'is_prn' => false,
            ])
            ->assertStatus(201);

        $r->assertJsonPath('pa_suggestion.required', true);
        $r->assertJsonPath('pa_suggestion.queue_url', '/pharmacy/prior-auth');
    }

    public function test_pa_not_required_drug_returns_null_suggestion(): void
    {
        [$t, $u, $p] = $this->setupRx();
        FormularyEntry::create([
            'tenant_id' => $t->id, 'rxnorm_code' => '29046',
            'drug_name' => 'Lisinopril', 'generic_name' => 'lisinopril',
            'tier' => 1, 'prior_authorization_required' => false,
            'is_active' => true,
        ]);

        $r = $this->actingAs($u)
            ->postJson("/participants/{$p->id}/medications", [
                'drug_name' => 'Lisinopril', 'dose' => 10, 'dose_unit' => 'mg',
                'route' => 'oral', 'frequency' => 'daily',
                'start_date' => now()->toDateString(), 'is_prn' => false,
            ])
            ->assertStatus(201);

        $r->assertJsonPath('pa_suggestion', null);
    }

    public function test_no_formulary_entry_returns_null_suggestion(): void
    {
        [$t, $u, $p] = $this->setupRx();
        $r = $this->actingAs($u)
            ->postJson("/participants/{$p->id}/medications", [
                'drug_name' => 'ObscureCompoundedRx', 'dose' => 1, 'dose_unit' => 'mg',
                'route' => 'oral', 'frequency' => 'daily',
                'start_date' => now()->toDateString(), 'is_prn' => false,
            ])
            ->assertStatus(201);
        $r->assertJsonPath('pa_suggestion', null);
    }
}
