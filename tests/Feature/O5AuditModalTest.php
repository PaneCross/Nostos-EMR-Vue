<?php

// ─── Phase O5 — audit-log detail endpoint returns shape needed by the modal ─
namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class O5AuditModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_endpoint_returns_user_and_diff_fields(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'it_admin', 'role' => 'admin', 'is_active' => true,
        ]);
        $log = AuditLog::create([
            'tenant_id' => $t->id,
            'user_id'   => $u->id,
            'action'    => 'participant.updated',
            'resource_type' => 'participant',
            'resource_id'   => 1,
            'description'   => 'Test edit',
            'old_values' => ['first_name' => 'Alice'],
            'new_values' => ['first_name' => 'Alicia'],
            'ip_address' => '10.0.0.1',
            'user_agent' => 'phpunit',
        ]);
        $this->actingAs($u);
        $r = $this->getJson("/it-admin/audit/log/{$log->id}");
        $r->assertOk()->assertJsonStructure([
            'log' => ['id', 'action', 'resource_type', 'resource_id', 'description',
                      'old_values', 'new_values', 'ip_address', 'user_agent',
                      'created_at', 'user' => ['first_name', 'last_name']],
        ]);
    }

    public function test_detail_endpoint_rejects_cross_tenant(): void
    {
        $t1 = Tenant::factory()->create();
        $t2 = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t1->id, 'department' => 'it_admin', 'role' => 'admin', 'is_active' => true,
        ]);
        $log = AuditLog::create([
            'tenant_id' => $t2->id,
            'action' => 'x', 'description' => 'y',
        ]);
        $this->actingAs($u);
        $this->getJson("/it-admin/audit/log/{$log->id}")->assertForbidden();
    }
}
