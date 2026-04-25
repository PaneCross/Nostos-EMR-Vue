<?php

// ─── Phase RS4 — Alert::SOURCE_MODULES updated + check-out cast normalized ─
namespace Tests\Feature;

use App\Models\Alert;
use App\Models\DayCenterAttendance;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RS4SourceModulesAndCastsTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_modules_list_covers_real_production_callers(): void
    {
        $expected = [
            'integration', 'documentation_compliance', 'roi', 'breach',
            'amendment', 'sentinel_event', 'restraint', 'tb_screening',
            'compliance', 'idt', 'anticoag', 'critical_value', 'bcma', 'scheduling',
        ];
        foreach ($expected as $module) {
            $this->assertContains($module, Alert::SOURCE_MODULES,
                "Alert::SOURCE_MODULES is missing production source_module '{$module}'");
        }
    }

    public function test_check_out_time_round_trips_as_string(): void
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'CO']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'activities', 'role' => 'admin', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        DayCenterAttendance::create([
            'tenant_id' => $t->id, 'site_id' => $site->id, 'participant_id' => $p->id,
            'attendance_date' => now()->toDateString(), 'status' => 'present',
            'check_in_time' => '09:00:00', 'check_out_time' => '15:30:00',
            'recorded_by_user_id' => $u->id,
        ]);
        $rec = DayCenterAttendance::first();
        $this->assertIsString($rec->check_out_time);
        $this->assertStringStartsWith('15:30', $rec->check_out_time);
    }
}
