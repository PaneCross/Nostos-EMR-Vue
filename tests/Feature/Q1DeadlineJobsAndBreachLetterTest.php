<?php

// ─── Phase Q1 — Deadline jobs + breach notification letter PDF ─────────────
namespace Tests\Feature;

use App\Jobs\AmendmentDeadlineJob;
use App\Jobs\BreachDeadlineJob;
use App\Models\Alert;
use App\Models\AmendmentRequest;
use App\Models\BreachIncident;
use App\Models\Participant;
use App\Models\ParticipantAddress;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FreezesTime;
use Tests\TestCase;

class Q1DeadlineJobsAndBreachLetterTest extends TestCase
{
    use FreezesTime;
    use RefreshDatabase;

    // Phase X6 — freeze "now" so deadline diffInDays math doesn't flake at
    // midnight UTC or DST crossovers when paratest workers race.
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFreezesTime();
    }

    protected function tearDown(): void
    {
        $this->tearDownFreezesTime();
        parent::tearDown();
    }

    private function tenantUser(string $dept = 'it_admin'): array
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => $dept,
            'role' => 'admin', 'is_active' => true,
        ]);
        return [$t, $u];
    }

    public function test_amendment_deadline_job_emits_t7_warning(): void
    {
        [$t, $u] = $this->tenantUser('qa_compliance');
        $prefix = strtoupper(\Illuminate\Support\Str::random(3));
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => $prefix]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();

        AmendmentRequest::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'requested_change' => 'X', 'status' => 'pending',
            'deadline_at' => now()->addDays(5),
        ]);

        app(AmendmentDeadlineJob::class)->handle(app(AlertService::class));

        $this->assertEquals(1, Alert::where('alert_type', 'amendment_deadline_t7')->count());
    }

    public function test_amendment_deadline_job_emits_missed_critical(): void
    {
        [$t, $u] = $this->tenantUser('qa_compliance');
        $prefix = strtoupper(\Illuminate\Support\Str::random(3));
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => $prefix]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();

        AmendmentRequest::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'requested_change' => 'X', 'status' => 'pending',
            'deadline_at' => now()->subDay(),
        ]);

        app(AmendmentDeadlineJob::class)->handle(app(AlertService::class));

        $this->assertEquals(1, Alert::where('alert_type', 'amendment_deadline_missed')
            ->where('severity', 'critical')->count());
    }

    public function test_breach_deadline_job_emits_t3_warning(): void
    {
        [$t, $u] = $this->tenantUser();
        BreachIncident::create([
            'tenant_id' => $t->id, 'logged_by_user_id' => $u->id,
            'discovered_at' => now()->subDays(57), 'breach_type' => 'hacking',
            'description' => 'X breach test for T-3 deadline path.',
            'affected_count' => 600,
            'hhs_deadline_at' => now()->addDays(2),
            'status' => 'open',
        ]);

        app(BreachDeadlineJob::class)->handle(app(AlertService::class));

        $this->assertEquals(1, Alert::where('alert_type', 'breach_hhs_deadline_t3')->count());
    }

    public function test_breach_deadline_job_emits_missed_critical(): void
    {
        [$t, $u] = $this->tenantUser();
        BreachIncident::create([
            'tenant_id' => $t->id, 'logged_by_user_id' => $u->id,
            'discovered_at' => now()->subDays(70), 'breach_type' => 'hacking',
            'description' => 'X breach test for missed-deadline path.',
            'affected_count' => 600,
            'hhs_deadline_at' => now()->subDays(2),
            'status' => 'open',
        ]);

        app(BreachDeadlineJob::class)->handle(app(AlertService::class));

        $this->assertEquals(1, Alert::where('alert_type', 'breach_hhs_deadline_missed')
            ->where('severity', 'critical')->count());
    }

    public function test_breach_letter_pdf_renders(): void
    {
        [$t, $u] = $this->tenantUser();
        $prefix = strtoupper(\Illuminate\Support\Str::random(3));
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => $prefix]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        ParticipantAddress::create([
            'participant_id' => $p->id, 'address_type' => 'home', 'is_primary' => true,
            'street' => '123 Main St', 'city' => 'Anytown', 'state' => 'CA', 'zip' => '94102',
        ]);
        $b = BreachIncident::create([
            'tenant_id' => $t->id, 'logged_by_user_id' => $u->id,
            'discovered_at' => now(), 'breach_type' => 'hacking',
            'description' => 'PDF letter render path.',
            'affected_count' => 100,
            'hhs_deadline_at' => now()->addDays(60),
            'status' => 'open',
        ]);

        $this->actingAs($u);
        $r = $this->get("/it-admin/breaches/{$b->id}/letter/{$p->id}");
        $r->assertOk();
        $this->assertEquals('application/pdf', $r->headers->get('content-type'));
    }
}
