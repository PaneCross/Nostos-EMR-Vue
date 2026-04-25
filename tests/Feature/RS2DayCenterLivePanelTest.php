<?php

// ─── Phase RS2 — DayCenter.vue consumes /event-status ──────────────────────
namespace Tests\Feature;

use Tests\TestCase;

class RS2DayCenterLivePanelTest extends TestCase
{
    public function test_day_center_vue_fetches_event_status_endpoint(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/Scheduling/DayCenter.vue'));
        $this->assertStringContainsString("/scheduling/day-center/event-status", $vue);
        $this->assertStringContainsString('data-testid="dc-live-roster"', $vue);
        $this->assertStringContainsString('/scheduling/day-center/roster.pdf', $vue);
    }
}
