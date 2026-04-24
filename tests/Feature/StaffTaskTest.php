<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\StaffTask;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffTaskTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->user  = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'pharmacy', 'role' => 'admin', 'is_active' => true]);
        $this->other = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'social_work', 'role' => 'admin', 'is_active' => true]);
    }

    public function test_store_requires_assignee(): void
    {
        $this->actingAs($this->user);
        $r = $this->postJson('/tasks', ['title' => 'x']);
        $r->assertStatus(422);
        $this->assertEquals('assignee_required', $r->json('error'));
    }

    public function test_store_to_user_creates_task(): void
    {
        $this->actingAs($this->user);
        $this->postJson('/tasks', [
            'title' => 'Review med rec',
            'assigned_to_user_id' => $this->other->id,
            'priority' => 'high',
        ])->assertStatus(201);
        $this->assertEquals(1, StaffTask::count());
    }

    public function test_store_to_department_creates_task(): void
    {
        $this->actingAs($this->user);
        $this->postJson('/tasks', [
            'title' => 'New admission intake',
            'assigned_to_department' => 'social_work',
        ])->assertStatus(201);
    }

    public function test_index_mine_returns_only_my_tasks(): void
    {
        StaffTask::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Mine',
            'assigned_to_user_id' => $this->user->id, 'priority' => 'normal', 'status' => 'pending',
        ]);
        StaffTask::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Theirs',
            'assigned_to_user_id' => $this->other->id, 'priority' => 'normal', 'status' => 'pending',
        ]);
        $this->actingAs($this->user);
        $r = $this->getJson('/tasks');
        $r->assertOk();
        $this->assertCount(1, $r->json('tasks'));
    }

    public function test_complete_task_sets_timestamp(): void
    {
        $task = StaffTask::create([
            'tenant_id' => $this->tenant->id, 'title' => 'x',
            'assigned_to_user_id' => $this->user->id, 'priority' => 'normal', 'status' => 'pending',
        ]);
        $this->actingAs($this->user);
        $this->postJson("/tasks/{$task->id}/complete", ['completion_note' => 'done'])->assertOk();
        $this->assertEquals('completed', $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_cannot_complete_terminal_task(): void
    {
        $task = StaffTask::create([
            'tenant_id' => $this->tenant->id, 'title' => 'x',
            'assigned_to_user_id' => $this->user->id, 'priority' => 'normal', 'status' => 'completed',
            'completed_at' => now(),
        ]);
        $this->actingAs($this->user);
        $this->postJson("/tasks/{$task->id}/complete", [])->assertStatus(409);
    }

    public function test_cross_tenant_complete_blocked(): void
    {
        $other = Tenant::factory()->create();
        $task = StaffTask::create([
            'tenant_id' => $other->id, 'title' => 'x',
            'assigned_to_user_id' => $this->user->id, 'priority' => 'normal', 'status' => 'pending',
        ]);
        $this->actingAs($this->user);
        $this->postJson("/tasks/{$task->id}/complete", [])->assertStatus(403);
    }
}
