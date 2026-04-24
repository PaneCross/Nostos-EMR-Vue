<?php

// ─── Phase I5 — /tasks Inertia queue page ───────────────────────────────────
namespace Tests\Feature;

use App\Models\StaffTask;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class I5TasksIndexUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'pharmacy',
            'role' => 'admin', 'is_active' => true,
        ]);
    }

    public function test_tasks_index_inertia_page_renders(): void
    {
        StaffTask::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Review med rec',
            'assigned_to_user_id' => $this->user->id, 'priority' => 'high',
            'status' => 'pending', 'due_at' => now()->addHours(2),
        ]);
        $this->actingAs($this->user);
        $r = $this->get('/tasks');
        $r->assertOk();
        $r->assertInertia(fn ($page) => $page
            ->component('Tasks/Index')
            ->where('view', 'mine')
            ->has('tasks', 1)
        );
    }

    public function test_tasks_json_still_works_when_accept_json(): void
    {
        StaffTask::create([
            'tenant_id' => $this->tenant->id, 'title' => 'x',
            'assigned_to_user_id' => $this->user->id, 'priority' => 'normal',
            'status' => 'pending',
        ]);
        $this->actingAs($this->user);
        $r = $this->getJson('/tasks');
        $r->assertOk();
        $r->assertJsonCount(1, 'tasks');
    }

    public function test_my_department_view_scopes_to_dept(): void
    {
        $other = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'social_work', 'role' => 'admin', 'is_active' => true]);
        StaffTask::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Pharm dept task',
            'assigned_to_department' => 'pharmacy', 'priority' => 'normal', 'status' => 'pending',
        ]);
        StaffTask::create([
            'tenant_id' => $this->tenant->id, 'title' => 'SW dept task',
            'assigned_to_department' => 'social_work', 'priority' => 'normal', 'status' => 'pending',
        ]);
        $this->actingAs($this->user);
        $r = $this->get('/tasks?view=my_department');
        $r->assertOk();
        $r->assertInertia(fn ($page) => $page
            ->component('Tasks/Index')
            ->where('view', 'my_department')
            ->has('tasks', 1)
        );
    }

    public function test_all_view_shows_tenant_wide(): void
    {
        $other = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'social_work', 'role' => 'admin', 'is_active' => true]);
        StaffTask::create([
            'tenant_id' => $this->tenant->id, 'title' => 'A',
            'assigned_to_user_id' => $this->user->id, 'priority' => 'normal', 'status' => 'pending',
        ]);
        StaffTask::create([
            'tenant_id' => $this->tenant->id, 'title' => 'B',
            'assigned_to_user_id' => $other->id, 'priority' => 'normal', 'status' => 'pending',
        ]);
        $this->actingAs($this->user);
        $r = $this->get('/tasks?view=all');
        $r->assertOk();
        $r->assertInertia(fn ($page) => $page->has('tasks', 2));
    }
}
