<?php

// ─── Phase W3 — CFR-aware validation messages on 5 user-visible forms ──────
namespace Tests\Feature;

use App\Http\Requests\FileAppealRequest;
use App\Http\Requests\RecordEmarAdministrationRequest;
use App\Http\Requests\StoreClinicalNoteRequest;
use App\Http\Requests\StoreGrievanceRequest;
use App\Http\Requests\SubmitRcaRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class W3CfrMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_clinical_note_messages_cite_460_210(): void
    {
        $messages = (new StoreClinicalNoteRequest())->messages();
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('§460.210', $messages['late_entry_reason.required_if'] ?? '');
        $this->assertStringContainsString('Note template', $messages['note_template_id.exists'] ?? '');
    }

    public function test_grievance_messages_cite_460_122(): void
    {
        $messages = (new StoreGrievanceRequest())->messages();
        $this->assertStringContainsString('§460.122', implode(' ', $messages));
    }

    public function test_appeal_messages_cite_460_122_and_distinguish_30day_vs_72h(): void
    {
        $messages = (new FileAppealRequest())->messages();
        $combined = implode(' ', $messages);
        $this->assertStringContainsString('§460.122', $combined);
        $this->assertStringContainsString('30-day', $combined);
        $this->assertStringContainsString('72-hour', $combined);
    }

    public function test_emar_messages_explain_audit_trail_requirement(): void
    {
        $messages = (new RecordEmarAdministrationRequest())->messages();
        $combined = implode(' ', $messages);
        $this->assertStringContainsString('audit trail', $combined);
        $this->assertStringContainsString('reason', $combined);
    }

    public function test_rca_messages_cite_460_136_qapi(): void
    {
        $messages = (new SubmitRcaRequest())->messages();
        $combined = implode(' ', $messages);
        $this->assertStringContainsString('§460.136', $combined);
        $this->assertStringContainsString('QAPI', $combined);
    }

    public function test_clinical_note_validation_surface_actually_uses_message(): void
    {
        // Live integration: post a partial form with is_late_entry=true but no
        // late_entry_reason → assert the response carries our domain wording,
        // not the Laravel default.
        $t = \App\Models\Tenant::factory()->create();
        $site = \App\Models\Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'CN']);
        $u = \App\Models\User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'primary_care', 'role' => 'admin', 'is_active' => true,
        ]);
        $p = \App\Models\Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();

        $r = $this->actingAs($u)->postJson("/participants/{$p->id}/notes", [
            'note_type'      => 'soap',
            'visit_type'     => 'in_center',
            'visit_date'     => now()->subDays(5)->toDateString(),
            'department'     => 'primary_care',
            'subjective'     => 'S', 'objective'  => 'O',
            'assessment'     => 'A', 'plan'       => 'P',
            'is_late_entry'  => true,
            // late_entry_reason intentionally missing.
        ]);

        $r->assertStatus(422);
        // JSON-encoded body uses § for the § character; compare against
        // the decoded message instead.
        $msg = $r->json('errors.late_entry_reason.0') ?? $r->json('message') ?? '';
        $this->assertStringContainsString('§460.210', $msg,
            'Expected CFR-cited late-entry message in 422 body.');
    }
}
