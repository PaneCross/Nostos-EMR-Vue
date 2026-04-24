<?php

// ─── Phase M2 — Audit detail + SNOMED ECL + task broadcast ─────────────────
namespace Tests\Feature;

use App\Events\TaskAssignedEvent;
use App\Models\AuditLog;
use App\Models\SnomedLookup;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class M2UiPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_show_returns_single_row(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $t->id, 'department' => 'it_admin', 'role' => 'admin', 'is_active' => true]);
        $log = AuditLog::create([
            'tenant_id' => $t->id, 'user_id' => $u->id,
            'action' => 'test.action', 'resource_type' => 'participant',
            'resource_id' => 1, 'description' => 'hello',
        ]);
        $this->actingAs($u);
        $r = $this->getJson("/it-admin/audit/log/{$log->id}");
        $r->assertOk()->assertJsonStructure(['log' => ['id', 'action', 'description']]);
    }

    public function test_snomed_ecl_descendant_returns_category_siblings(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $t->id, 'department' => 'primary_care', 'role' => 'admin', 'is_active' => true]);
        SnomedLookup::create(['code' => 'PARENT1', 'display' => 'Dementia', 'category' => 'dementia']);
        SnomedLookup::create(['code' => 'CHILD1',  'display' => 'Alzheimer disease', 'category' => 'dementia']);
        SnomedLookup::create(['code' => 'OTHER1',  'display' => 'Unrelated', 'category' => 'other']);
        $this->actingAs($u);
        $r = $this->getJson('/coding/snomed?q=' . urlencode('<<PARENT1'));
        $r->assertOk();
        $codes = collect($r->json('results'))->pluck('code')->all();
        $this->assertContains('CHILD1', $codes);
        $this->assertNotContains('OTHER1', $codes);
        $this->assertNotContains('PARENT1', $codes);
    }

    public function test_task_created_broadcasts_event(): void
    {
        Event::fake([TaskAssignedEvent::class]);
        $t = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $t->id, 'department' => 'primary_care', 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($u);
        $this->postJson('/tasks', [
            'title' => 'M2 test task',
            'assigned_to_user_id' => $u->id,
            'priority' => 'high',
        ])->assertStatus(201);
        Event::assertDispatched(TaskAssignedEvent::class);
    }
}
