<?php

// ─── NoteTemplateTest ────────────────────────────────────────────────────────
// Phase B7 — note templates library, renderer, problem linkage, signing hook.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\ClinicalNote;
use App\Models\NoteTemplate;
use App\Models\Participant;
use App\Models\Problem;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NoteTemplateRenderer;
use Database\Seeders\SystemNoteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoteTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $pcp;
    private User $qa;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'NT']);
        $this->pcp = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'site_id' => $this->site->id,
            'department' => 'primary_care', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->qa = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'site_id' => $this->site->id,
            'department' => 'qa_compliance', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)
            ->create(['first_name' => 'Alice', 'last_name' => 'Smith']);
    }

    public function test_system_seeder_ships_11_templates(): void
    {
        (new SystemNoteTemplateSeeder())->run();
        $this->assertGreaterThanOrEqual(11, NoteTemplate::where('is_system', true)->count());
    }

    public function test_qa_can_create_tenant_template(): void
    {
        $this->actingAs($this->qa);
        $r = $this->postJson('/note-templates', [
            'name' => 'My Custom CHF',
            'note_type' => 'soap',
            'department' => 'primary_care',
            'body_markdown' => '# {{participant.name}} — CHF check',
        ]);
        $r->assertStatus(201);
        $this->assertDatabaseHas('emr_note_templates', [
            'name' => 'My Custom CHF', 'tenant_id' => $this->tenant->id, 'is_system' => false,
        ]);
    }

    public function test_non_qa_cannot_create_template(): void
    {
        $this->actingAs($this->pcp);
        $r = $this->postJson('/note-templates', [
            'name' => 'Nope', 'note_type' => 'soap', 'body_markdown' => 'x',
        ]);
        $r->assertStatus(403);
    }

    public function test_renderer_substitutes_placeholders(): void
    {
        $tpl = NoteTemplate::create([
            'tenant_id' => null, 'name' => 'Test', 'note_type' => 'soap',
            'body_markdown' => 'Name: {{participant.name}} · MRN: {{participant.mrn}} · Today: {{today}} · Provider: {{provider.name}}',
            'is_system' => true,
        ]);
        $rendered = (new NoteTemplateRenderer())->render($tpl, $this->participant, $this->pcp);
        $this->assertStringContainsString('Smith, Alice', $rendered);
        $this->assertStringContainsString($this->participant->mrn, $rendered);
        $this->assertStringContainsString(now()->toDateString(), $rendered);
        $this->assertStringContainsString($this->pcp->first_name, $rendered);
    }

    public function test_render_endpoint_returns_filled_body(): void
    {
        $tpl = NoteTemplate::create([
            'tenant_id' => null, 'name' => 'Ren', 'note_type' => 'soap',
            'body_markdown' => 'MRN: {{participant.mrn}}',
            'is_system' => true,
        ]);
        $this->actingAs($this->pcp);
        $r = $this->getJson("/note-templates/{$tpl->id}/render/{$this->participant->id}");
        $r->assertOk();
        $this->assertStringContainsString($this->participant->mrn, $r->json('rendered'));
    }

    public function test_note_store_links_primary_and_secondary_problems(): void
    {
        $primary = Problem::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'icd10_code' => 'E11.9', 'icd10_description' => 'Type 2 diabetes', 'status' => 'active',
            'onset_date' => now()->subYears(3),
        ]);
        $secondary = Problem::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'icd10_code' => 'I10', 'icd10_description' => 'Essential hypertension', 'status' => 'active',
            'onset_date' => now()->subYears(5),
        ]);

        $this->actingAs($this->pcp);
        $r = $this->postJson("/participants/{$this->participant->id}/notes", [
            'note_type' => 'soap', 'visit_type' => 'in_center',
            'visit_date' => today()->toDateString(), 'department' => 'primary_care',
            'subjective' => 'x', 'objective' => 'x', 'assessment' => 'x', 'plan' => 'x',
            'primary_problem_id' => $primary->id,
            'secondary_problem_ids' => [$secondary->id],
        ]);
        $r->assertStatus(201);

        $note = ClinicalNote::latest()->first();
        $this->assertEquals(2, $note->linkedProblems()->count());
        $this->assertTrue(
            $note->linkedProblems()->wherePivot('is_primary', true)->where('emr_problems.id', $primary->id)->exists()
        );
    }

    public function test_notes_for_problem_returns_linked_notes(): void
    {
        $problem = Problem::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'icd10_code' => 'I10', 'icd10_description' => 'HTN', 'status' => 'active',
            'onset_date' => now()->subYears(1),
        ]);
        $note = ClinicalNote::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'site_id' => $this->site->id, 'note_type' => 'soap', 'status' => 'draft',
            'authored_by_user_id' => $this->pcp->id, 'department' => 'primary_care',
            'visit_type' => 'in_center', 'visit_date' => today(),
        ]);
        $note->linkedProblems()->attach($problem->id, ['is_primary' => true]);

        $this->actingAs($this->pcp);
        $r = $this->getJson("/problems/{$problem->id}/notes");
        $r->assertOk();
        $this->assertCount(1, $r->json('notes'));
    }

    public function test_cross_tenant_template_render_blocked(): void
    {
        $other = Tenant::factory()->create();
        $tpl = NoteTemplate::create([
            'tenant_id' => $other->id, 'name' => 'Theirs', 'note_type' => 'soap',
            'body_markdown' => 'x', 'is_system' => false,
        ]);
        $this->actingAs($this->pcp);
        $r = $this->getJson("/note-templates/{$tpl->id}/render/{$this->participant->id}");
        $r->assertStatus(403);
    }

    public function test_secondary_problem_only_does_not_set_primary(): void
    {
        $p = Problem::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'icd10_code' => 'J44.9', 'icd10_description' => 'COPD', 'status' => 'active',
            'onset_date' => now()->subYears(2),
        ]);
        $this->actingAs($this->pcp);
        $this->postJson("/participants/{$this->participant->id}/notes", [
            'note_type' => 'soap', 'visit_type' => 'in_center',
            'visit_date' => today()->toDateString(), 'department' => 'primary_care',
            'subjective' => 'x', 'objective' => 'x', 'assessment' => 'x', 'plan' => 'x',
            'secondary_problem_ids' => [$p->id],
        ])->assertStatus(201);

        $note = ClinicalNote::latest()->first();
        $this->assertEquals(0, $note->linkedProblems()->wherePivot('is_primary', true)->count());
        $this->assertEquals(1, $note->linkedProblems()->count());
    }
}
