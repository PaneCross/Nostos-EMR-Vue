<?php

namespace Tests\Feature;

use App\Models\ActivityAttendance;
use App\Models\ActivityEvent;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $activities;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'AC']);
        $this->activities = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'activities', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->participant = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    public function test_activities_dept_can_create_event(): void
    {
        $this->actingAs($this->activities);
        $r = $this->postJson('/activities', [
            'title' => 'Music Therapy',
            'category' => 'creative',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'duration_min' => 45,
            'location' => 'Day Center - Room A',
        ]);
        $r->assertStatus(201);
        $this->assertEquals(1, ActivityEvent::count());
    }

    public function test_record_attendance_is_idempotent(): void
    {
        $event = ActivityEvent::create([
            'tenant_id' => $this->tenant->id, 'title' => 'x', 'category' => 'physical',
            'scheduled_at' => now(), 'duration_min' => 30,
        ]);
        $this->actingAs($this->activities);
        $this->postJson("/activities/{$event->id}/attendance", [
            'participant_id' => $this->participant->id,
            'attendance_status' => 'attended', 'engagement_level' => 'high',
        ])->assertStatus(201);
        // Repeat — should update, not create a second row.
        $this->postJson("/activities/{$event->id}/attendance", [
            'participant_id' => $this->participant->id,
            'attendance_status' => 'attended', 'engagement_level' => 'med',
        ])->assertStatus(201);
        $this->assertEquals(1, ActivityAttendance::count());
        $this->assertEquals('med', ActivityAttendance::first()->engagement_level);
    }

    public function test_index_filters_by_date_range(): void
    {
        ActivityEvent::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Today', 'category' => 'social',
            'scheduled_at' => now(), 'duration_min' => 60,
        ]);
        ActivityEvent::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Far future', 'category' => 'social',
            'scheduled_at' => now()->addDays(30), 'duration_min' => 60,
        ]);
        $this->actingAs($this->activities);
        $r = $this->getJson('/activities');
        $r->assertOk();
        $this->assertCount(1, $r->json('events'));
    }

    public function test_participant_trend_computes_engagement_pct(): void
    {
        foreach (['high', 'high', 'low', 'med'] as $i => $level) {
            $event = ActivityEvent::create([
                'tenant_id' => $this->tenant->id, 'title' => "A{$i}", 'category' => 'social',
                'scheduled_at' => now()->subDays($i), 'duration_min' => 30,
            ]);
            ActivityAttendance::create([
                'tenant_id' => $this->tenant->id, 'activity_event_id' => $event->id,
                'participant_id' => $this->participant->id,
                'attendance_status' => 'attended', 'engagement_level' => $level,
            ]);
        }
        $this->actingAs($this->activities);
        $r = $this->getJson("/participants/{$this->participant->id}/activity-trend");
        $r->assertOk();
        $this->assertEquals(4, $r->json('summary.total'));
        $this->assertEquals(50.0, $r->json('summary.high_engagement_pct'));
    }

    public function test_cross_tenant_attendance_blocked(): void
    {
        $other = Tenant::factory()->create();
        $event = ActivityEvent::create([
            'tenant_id' => $other->id, 'title' => 'x', 'category' => 'social',
            'scheduled_at' => now(), 'duration_min' => 30,
        ]);
        $this->actingAs($this->activities);
        $this->postJson("/activities/{$event->id}/attendance", [
            'participant_id' => $this->participant->id,
            'attendance_status' => 'attended',
        ])->assertStatus(403);
    }
}
