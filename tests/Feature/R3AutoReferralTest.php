<?php

// ─── Phase R3 — IADL + assessment referrals auto-create StaffTask ──────────
namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\StaffTask;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R3AutoReferralTest extends TestCase
{
    use RefreshDatabase;

    private function setupClinical(): array
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'AR']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'primary_care', 'role' => 'admin', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        return [$t, $u, $p];
    }

    public function test_iadl_finances_impaired_creates_social_work_task(): void
    {
        [$t, $u, $p] = $this->setupClinical();
        $payload = [
            'telephone' => 1, 'shopping' => 1, 'food_preparation' => 1, 'housekeeping' => 1,
            'laundry' => 1, 'transportation' => 1, 'medications' => 1, 'finances' => 0,
        ];

        $this->actingAs($u)->postJson("/participants/{$p->id}/iadl", $payload)->assertStatus(201);

        $this->assertEquals(1, StaffTask::where('participant_id', $p->id)
            ->where('assigned_to_department', 'social_work')->count());
    }

    public function test_iadl_three_impaired_items_creates_three_tasks(): void
    {
        [$t, $u, $p] = $this->setupClinical();
        // food_preparation→dietary, medications→pharmacy, transportation→transportation
        $payload = [
            'telephone' => 1, 'shopping' => 1, 'food_preparation' => 0, 'housekeeping' => 1,
            'laundry' => 1, 'transportation' => 0, 'medications' => 0, 'finances' => 1,
        ];

        $this->actingAs($u)->postJson("/participants/{$p->id}/iadl", $payload)->assertStatus(201);

        $this->assertEquals(3, StaffTask::where('participant_id', $p->id)->count());
        $this->assertEqualsCanonicalizing(
            ['dietary', 'pharmacy', 'transportation'],
            StaffTask::where('participant_id', $p->id)->pluck('assigned_to_department')->all(),
        );
    }

    public function test_audit_c_positive_creates_behavioral_health_task(): void
    {
        [$t, $u, $p] = $this->setupClinical();

        $r = $this->actingAs($u)->postJson("/participants/{$p->id}/assessments", [
            'assessment_type' => 'audit_c_alcohol',
            'completed_at' => now()->toDateTimeString(),
            'responses' => ['q1' => 4, 'q2' => 0, 'q3' => 0],
            'score' => 4,
        ]);
        $r->assertStatus(201);

        $this->assertEquals(1, StaffTask::where('participant_id', $p->id)
            ->where('assigned_to_department', 'behavioral_health')->count());
    }
}
