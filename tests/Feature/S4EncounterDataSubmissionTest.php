<?php

// ─── Phase S4 — Encounter Data Submission gateway scaffold ─────────────────
namespace Tests\Feature;

use App\Models\EdiBatch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class S4EncounterDataSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_with_null_driver_default(): void
    {
        config(['services.encounter_data.driver' => 'null']);
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'finance',
            'role' => 'admin', 'is_active' => true,
        ]);

        $this->actingAs($u);
        $this->get('/billing/encounter-data-submission')->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Billing/EncounterDataSubmission')
                ->where('driver', 'null')
                ->where('is_real_vendor', false)
            );
    }

    public function test_submit_via_null_gateway_marks_batch_submitted(): void
    {
        config(['services.encounter_data.driver' => 'null']);
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'finance',
            'role' => 'admin', 'is_active' => true,
        ]);
        $batch = EdiBatch::create([
            'tenant_id' => $t->id, 'batch_type' => 'edr',
            'file_name' => 'edr-test.txt', 'file_content' => 'ISA*...',
            'record_count' => 5, 'total_charge_amount' => 1250.00,
            'status' => 'draft', 'created_by_user_id' => $u->id,
        ]);

        $this->actingAs($u);
        $this->postJson("/billing/encounter-data-submission/{$batch->id}/submit")
            ->assertOk()
            ->assertJsonPath('gateway', 'null');
        $this->assertEquals('submitted', $batch->fresh()->status);
        $this->assertNotNull($batch->fresh()->submitted_at);
    }
}
