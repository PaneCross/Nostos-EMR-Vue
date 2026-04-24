<?php

// ─── Phase I3 — Note template picker + problem linkage + addendum UI ────────
// UI is tested at a behavior level via the endpoints the UI consumes.

namespace Tests\Feature;

use App\Models\ClinicalNote;
use App\Models\NoteTemplate;
use App\Models\Participant;
use App\Models\Problem;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\SystemNoteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class I3NoteCreationFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $pcp;
    private Participant $participant;
    private Problem $primaryProblem;
    private Problem $secondaryProblem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'I3']);
        $this->pcp = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        $this->primaryProblem = Problem::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'icd10_code' => 'E11.9', 'icd10_description' => 'Type 2 diabetes',
            'status' => 'active', 'onset_date' => now()->subYears(3),
        ]);
        $this->secondaryProblem = Problem::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'icd10_code' => 'I10', 'icd10_description' => 'Essential hypertension',
            'status' => 'active', 'onset_date' => now()->subYears(5),
        ]);
    }

    public function test_templates_list_loads_with_system_templates(): void
    {
        (new SystemNoteTemplateSeeder())->run();
        $this->actingAs($this->pcp);
        $r = $this->getJson('/note-templates');
        $r->assertOk();
        $this->assertGreaterThan(0, count($r->json('templates')));
    }

    public function test_template_render_returns_participant_prefilled_body(): void
    {
        (new SystemNoteTemplateSeeder())->run();
        $tpl = NoteTemplate::where('is_system', true)->where('note_type', 'soap')->first();
        $this->actingAs($this->pcp);
        $r = $this->getJson("/note-templates/{$tpl->id}/render/{$this->participant->id}");
        $r->assertOk();
        $this->assertStringContainsString($this->participant->first_name, $r->json('rendered'));
    }

    public function test_note_store_accepts_template_and_problem_linkage(): void
    {
        (new SystemNoteTemplateSeeder())->run();
        $tpl = NoteTemplate::where('is_system', true)->where('note_type', 'soap')->first();
        $this->actingAs($this->pcp);
        $r = $this->postJson("/participants/{$this->participant->id}/notes", [
            'note_type' => 'soap', 'visit_type' => 'in_center',
            'visit_date' => today()->toDateString(), 'department' => 'primary_care',
            'subjective' => 'x', 'objective' => 'x', 'assessment' => 'x', 'plan' => 'x',
            'note_template_id' => $tpl->id,
            'primary_problem_id' => $this->primaryProblem->id,
            'secondary_problem_ids' => [$this->secondaryProblem->id],
        ]);
        $r->assertStatus(201);
        $note = ClinicalNote::latest()->first();
        $this->assertEquals($tpl->id, $note->note_template_id);
        $this->assertEquals(2, $note->linkedProblems()->count());
        $this->assertTrue(
            $note->linkedProblems()->wherePivot('is_primary', true)
                ->where('emr_problems.id', $this->primaryProblem->id)->exists()
        );
    }

    public function test_addendum_endpoint_persists_content_notes(): void
    {
        $signed = ClinicalNote::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'site_id' => $this->site->id,
            'note_type' => 'soap', 'status' => 'signed',
            'authored_by_user_id' => $this->pcp->id, 'department' => 'primary_care',
            'visit_type' => 'in_center', 'visit_date' => today(),
            'subjective' => 'original', 'signed_at' => now()->subHour(),
            'signed_by_user_id' => $this->pcp->id,
        ]);
        $this->actingAs($this->pcp);
        $r = $this->postJson("/participants/{$this->participant->id}/notes/{$signed->id}/addendum", [
            'note_type' => 'addendum', 'visit_type' => 'in_center',
            'visit_date' => today()->toDateString(), 'department' => 'primary_care',
            'content' => ['notes' => 'Correction: dose adjusted to 20mg.'],
        ]);
        $r->assertStatus(201);
        $addendum = ClinicalNote::where('parent_note_id', $signed->id)->first();
        $this->assertNotNull($addendum);
        $this->assertEquals('addendum', $addendum->note_type);
        $this->assertEquals('Correction: dose adjusted to 20mg.', $addendum->content['notes'] ?? null);
    }

    public function test_problems_endpoint_returns_active_list_for_ui(): void
    {
        // Resolved problems must not appear in the UI picker
        Problem::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'icd10_code' => 'J20.9', 'icd10_description' => 'Acute bronchitis',
            'status' => 'resolved', 'onset_date' => now()->subMonths(6),
            'resolved_date' => now()->subMonths(3),
        ]);
        $this->actingAs($this->pcp);
        $r = $this->getJson("/participants/{$this->participant->id}/problems");
        $r->assertOk();
        // Endpoint returns status-grouped object: {active: [...], resolved: [...]}.
        $active = $r->json('active') ?? [];
        $resolved = $r->json('resolved') ?? [];
        $this->assertCount(2, $active);
        $this->assertCount(1, $resolved);
    }
}
