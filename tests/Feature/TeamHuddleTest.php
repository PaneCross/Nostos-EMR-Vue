<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\StaffTask;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamHuddleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $pharm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'G5']);
        $this->pharm = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'pharmacy', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
    }

    public function test_huddle_surfaces_overdue_tasks_for_department(): void
    {
        StaffTask::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Med rec',
            'assigned_to_department' => 'pharmacy', 'priority' => 'high',
            'status' => 'pending', 'due_at' => now()->subDay(),
        ]);
        StaffTask::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Not mine',
            'assigned_to_department' => 'social_work', 'priority' => 'high',
            'status' => 'pending', 'due_at' => now()->subDay(),
        ]);
        $this->actingAs($this->pharm);
        $r = $this->getJson('/huddle');
        $r->assertOk();
        $this->assertCount(1, $r->json('overdue_tasks'));
    }

    public function test_huddle_lists_new_admissions_in_last_24h(): void
    {
        Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)
            ->create(['enrollment_date' => now()->subHours(5)]);
        Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)
            ->create(['enrollment_date' => now()->subDays(5)]);
        $this->actingAs($this->pharm);
        $r = $this->getJson('/huddle');
        $r->assertOk();
        $this->assertCount(1, $r->json('new_admissions'));
    }

    public function test_huddle_pdf_renders(): void
    {
        $this->actingAs($this->pharm);
        $r = $this->get('/huddle/pdf');
        $r->assertOk();
        $r->assertHeader('content-type', 'application/pdf');
    }

    public function test_huddle_department_override_works(): void
    {
        StaffTask::create([
            'tenant_id' => $this->tenant->id, 'title' => 'SW task',
            'assigned_to_department' => 'social_work', 'priority' => 'high',
            'status' => 'pending', 'due_at' => now()->subDay(),
        ]);
        $this->actingAs($this->pharm);
        $r = $this->getJson('/huddle?department=social_work');
        $r->assertOk();
        $this->assertEquals('social_work', $r->json('department'));
        $this->assertCount(1, $r->json('overdue_tasks'));
    }
}
