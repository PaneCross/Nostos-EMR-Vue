<?php

// ─── Phase S1 — POA / Healthcare Proxy / Spouse contact role designations ──
namespace Tests\Feature;

use App\Models\Participant;
use App\Models\ParticipantContact;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class S1ContactLegalRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_can_be_marked_durable_poa_and_spouse(): void
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'CN']);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();

        $c = ParticipantContact::create([
            'participant_id' => $p->id, 'contact_type' => 'poa',
            'first_name' => 'Jane', 'last_name' => 'Doe',
            'is_legal_representative' => true,
            'legal_role'        => 'durable_poa',
            'relationship_role' => 'spouse',
        ]);

        $this->assertEquals('durable_poa', $c->fresh()->legal_role);
        $this->assertEquals('spouse', $c->fresh()->relationship_role);
    }

    public function test_check_constraint_rejects_invalid_legal_role(): void
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'CR']);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('emr_participant_contacts')->insert([
            'participant_id' => $p->id, 'contact_type' => 'other',
            'first_name' => 'X', 'last_name' => 'Y',
            'legal_role' => 'super_poa', // not in enum
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
