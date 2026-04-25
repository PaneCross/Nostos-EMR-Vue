<?php

// ─── Phase O11 — UX polish bundle ──────────────────────────────────────────
namespace Tests\Feature;

use App\Models\StateMedicaidSubmission;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class O11UxPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_medicaid_index_dual_serves_with_banner(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'finance', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($u);

        $json = $this->getJson('/state-medicaid/submissions');
        $json->assertOk()
            ->assertJsonStructure(['submissions', 'banner'])
            ->assertJsonFragment(['banner' => \App\Http\Controllers\StateMedicaidSubmissionController::HONEST_BANNER]);

        $html = $this->get('/state-medicaid/submissions');
        $html->assertOk()->assertInertia(fn ($p) => $p
            ->component('Operations/StateMedicaidSubmissions')
            ->where('banner', \App\Http\Controllers\StateMedicaidSubmissionController::HONEST_BANNER)
        );
    }

    public function test_voice_button_unsupported_message_present_in_source(): void
    {
        $vue = file_get_contents(resource_path('js/Components/Voice/VoiceNoteButton.vue'));
        $this->assertStringContainsString('Voice entry not supported', $vue);
        $this->assertStringContainsString('data-testid="voice-note-unsupported"', $vue);
    }

    public function test_participant_header_chip_failure_states_in_source(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/Participants/Components/ParticipantHeader.vue'));
        $this->assertStringContainsString('chip-beers-failed', $vue);
        $this->assertStringContainsString('chip-risk-failed', $vue);
        $this->assertStringContainsString('chip-care-gaps-failed', $vue);
    }
}
