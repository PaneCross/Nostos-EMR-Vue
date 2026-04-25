<?php

// ─── Phase S3 — DME tracking lifecycle ─────────────────────────────────────
namespace Tests\Feature;

use App\Models\DmeIssuance;
use App\Models\DmeItem;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class S3DmeTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function setupDme(): array
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'DM']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'therapies', 'role' => 'admin', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        return [$t, $u, $p];
    }

    public function test_full_dme_lifecycle_register_issue_return(): void
    {
        [$t, $u, $p] = $this->setupDme();
        $this->actingAs($u);

        $r = $this->postJson('/network/dme', [
            'item_type' => 'walker', 'manufacturer' => 'Drive Medical', 'model' => '10210-1',
            'serial_number' => 'WK-001', 'hcpcs_code' => 'E0143',
        ])->assertStatus(201);
        $itemId = $r->json('item.id');
        $this->assertEquals('available', DmeItem::find($itemId)->status);

        $r = $this->postJson("/network/dme/{$itemId}/issue", [
            'participant_id' => $p->id,
            'issued_at' => now()->toDateString(),
            'expected_return_at' => now()->addMonth()->toDateString(),
        ])->assertStatus(201);
        $issuanceId = $r->json('issuance.id');
        $this->assertEquals('issued', DmeItem::find($itemId)->status);

        $this->postJson("/network/dme/issuances/{$issuanceId}/return", [
            'returned_at' => now()->toDateString(),
            'return_condition' => 'good',
        ])->assertOk();
        $this->assertEquals('available', DmeItem::find($itemId)->status);
        $this->assertNotNull(DmeIssuance::find($issuanceId)->returned_at);
    }

    public function test_issuing_already_issued_item_is_422(): void
    {
        [$t, $u, $p] = $this->setupDme();
        $this->actingAs($u);
        $item = DmeItem::create([
            'tenant_id' => $t->id, 'item_type' => 'wheelchair', 'status' => 'issued',
        ]);
        $this->postJson("/network/dme/{$item->id}/issue", [
            'participant_id' => $p->id, 'issued_at' => now()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_lost_return_marks_item_lost(): void
    {
        [$t, $u, $p] = $this->setupDme();
        $this->actingAs($u);
        $item = DmeItem::create(['tenant_id' => $t->id, 'item_type' => 'cpap', 'status' => 'available']);
        $this->postJson("/network/dme/{$item->id}/issue", [
            'participant_id' => $p->id, 'issued_at' => now()->subMonth()->toDateString(),
        ])->assertStatus(201);
        $issuance = DmeIssuance::first();
        $this->postJson("/network/dme/issuances/{$issuance->id}/return", [
            'returned_at' => now()->toDateString(), 'return_condition' => 'lost',
        ])->assertOk();
        $this->assertEquals('lost', DmeItem::find($item->id)->status);
    }
}
