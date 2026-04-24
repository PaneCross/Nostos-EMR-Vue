<?php

// ─── HospiceWorkflowTest ─────────────────────────────────────────────────────
// Phase C3 — hospice lifecycle + comfort-care order set + bereavement + IDT.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\HospiceIdtReviewOverdueJob;
use App\Models\Alert;
use App\Models\BereavementContact;
use App\Models\ClinicalOrder;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\HospiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HospiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $pcp;
    private User $sw;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'HS']);
        $this->pcp = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'primary_care',
            'site_id' => $this->site->id, 'role' => 'admin', 'is_active' => true,
        ]);
        $this->sw = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'social_work',
            'site_id' => $this->site->id, 'role' => 'admin', 'is_active' => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    public function test_refer_sets_status_and_provider(): void
    {
        $this->actingAs($this->sw);
        $r = $this->postJson("/participants/{$this->participant->id}/hospice/refer", [
            'hospice_provider_text' => 'Serenity Hospice',
            'hospice_diagnosis_text' => 'End-stage CHF',
        ]);
        $r->assertOk();
        $this->participant->refresh();
        $this->assertEquals('referred', $this->participant->hospice_status);
        $this->assertEquals('Serenity Hospice', $this->participant->hospice_provider_text);
    }

    public function test_enroll_creates_comfort_care_bundle(): void
    {
        $this->participant->update(['hospice_status' => 'referred']);
        $this->actingAs($this->pcp);
        $r = $this->postJson("/participants/{$this->participant->id}/hospice/enroll", [
            'hospice_provider_text' => 'Serenity Hospice',
            'hospice_diagnosis_text' => 'End-stage CHF',
        ]);
        $r->assertStatus(201);

        $this->participant->refresh();
        $this->assertEquals('enrolled', $this->participant->hospice_status);
        $this->assertNotNull($this->participant->hospice_started_at);
        $this->assertNotNull($this->participant->hospice_last_idt_review_at);

        $orders = ClinicalOrder::where('participant_id', $this->participant->id)->get();
        $this->assertCount(5, $orders);
        $this->assertTrue($orders->every(fn ($o) => $o->priority === 'urgent' && $o->status === 'pending'));
    }

    public function test_cannot_enroll_a_deceased_participant(): void
    {
        $this->participant->update(['hospice_status' => 'deceased']);
        $this->actingAs($this->pcp);
        $r = $this->postJson("/participants/{$this->participant->id}/hospice/enroll", []);
        $r->assertStatus(422);
        $this->assertEquals('invalid_state', $r->json('error'));
    }

    public function test_idt_review_requires_enrolled_state(): void
    {
        $this->actingAs($this->pcp);
        $r = $this->postJson("/participants/{$this->participant->id}/hospice/idt-review", []);
        $r->assertStatus(422);
    }

    public function test_idt_review_updates_timestamp(): void
    {
        $this->participant->update([
            'hospice_status' => 'enrolled',
            'hospice_started_at' => now()->subDays(200),
            'hospice_last_idt_review_at' => now()->subDays(200),
        ]);
        $this->actingAs($this->pcp);
        $this->postJson("/participants/{$this->participant->id}/hospice/idt-review", [
            'notes' => 'Weekly huddle — plan stable.',
        ])->assertOk();
        $this->participant->refresh();
        $this->assertTrue($this->participant->hospice_last_idt_review_at->isToday());
    }

    public function test_record_death_disenrolls_and_schedules_bereavement(): void
    {
        $this->participant->update([
            'hospice_status' => 'enrolled',
            'hospice_started_at' => now()->subDays(30),
        ]);
        $this->actingAs($this->sw);
        $r = $this->postJson("/participants/{$this->participant->id}/hospice/death", [
            'date_of_death'        => now()->subDay()->toDateString(),
            'family_contact_name'  => 'Jane Doe',
            'family_contact_phone' => '555-1212',
        ]);
        $r->assertStatus(201);

        $this->participant->refresh();
        $this->assertEquals('deceased', $this->participant->hospice_status);
        $this->assertEquals('disenrolled', $this->participant->enrollment_status);
        $this->assertEquals('death', $this->participant->disenrollment_type);

        $contacts = BereavementContact::where('participant_id', $this->participant->id)->get();
        $this->assertCount(3, $contacts);
        $this->assertEqualsCanonicalizing(['day_15', 'day_30', 'month_3'],
            $contacts->pluck('contact_type')->all());
    }

    public function test_cannot_record_death_twice(): void
    {
        $this->participant->update(['hospice_status' => 'deceased']);
        $this->actingAs($this->sw);
        $r = $this->postJson("/participants/{$this->participant->id}/hospice/death", [
            'date_of_death' => now()->subDay()->toDateString(),
        ]);
        $r->assertStatus(409);
    }

    public function test_complete_bereavement_marks_contact(): void
    {
        $contact = BereavementContact::create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'contact_type' => 'day_15',
            'scheduled_at' => now()->addDays(1),
            'status' => 'scheduled',
        ]);
        $this->actingAs($this->sw);
        $r = $this->postJson("/bereavement-contacts/{$contact->id}/complete", [
            'outcome' => 'completed',
            'notes'   => 'Spoke with daughter; offered grief resources.',
        ]);
        $r->assertOk();
        $this->assertEquals('completed', $contact->fresh()->status);
    }

    public function test_idt_overdue_job_alerts_after_180_days(): void
    {
        $this->participant->update([
            'hospice_status' => 'enrolled',
            'hospice_started_at' => now()->subDays(200),
            'hospice_last_idt_review_at' => now()->subDays(200),
        ]);
        (new HospiceIdtReviewOverdueJob())->handle(app(\App\Services\AlertService::class));
        $this->assertTrue(Alert::where('alert_type', 'hospice_idt_review_overdue')
            ->whereRaw("(metadata->>'participant_id')::int = ?", [$this->participant->id])
            ->exists());
    }

    public function test_idt_overdue_job_skips_recently_reviewed(): void
    {
        $this->participant->update([
            'hospice_status' => 'enrolled',
            'hospice_started_at' => now()->subDays(300),
            'hospice_last_idt_review_at' => now()->subDays(10),
        ]);
        (new HospiceIdtReviewOverdueJob())->handle(app(\App\Services\AlertService::class));
        $this->assertFalse(Alert::where('alert_type', 'hospice_idt_review_overdue')->exists());
    }

    public function test_cross_tenant_refer_blocked(): void
    {
        $other = Tenant::factory()->create();
        $otherSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'XT']);
        $otherP = Participant::factory()->enrolled()
            ->forTenant($other->id)->forSite($otherSite->id)->create();
        $this->actingAs($this->sw);
        $this->postJson("/participants/{$otherP->id}/hospice/refer", [])->assertStatus(403);
    }
}
