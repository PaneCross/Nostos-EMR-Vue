<?php

// ─── Phase V7 — RunMeeting mobile grid + DME prompt/alert cleanup ──────────
namespace Tests\Feature;

use Tests\TestCase;

class V7CleanupsTest extends TestCase
{
    public function test_run_meeting_action_items_grid_is_responsive(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/Idt/RunMeeting.vue'));
        $this->assertStringNotContainsString('flex-1 grid grid-cols-3 gap-2', $vue);
        $this->assertStringContainsString('flex-1 grid grid-cols-1 sm:grid-cols-3 gap-2', $vue);
    }

    public function test_dme_return_uses_inline_form_not_window_prompt(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/Network/Dme.vue'));
        $this->assertStringNotContainsString("window.prompt('Return condition", $vue);
        $this->assertStringContainsString('data-testid="dme-return-form"', $vue);
        $this->assertStringContainsString('startReturn', $vue);
        $this->assertStringContainsString('submitReturn', $vue);
    }
}
