<?php

// ─── Phase V2 — IDT auto-save errors surface to user ───────────────────────
namespace Tests\Feature;

use Tests\TestCase;

class V2IdtAutoSaveErrorSurfaceTest extends TestCase
{
    public function test_run_meeting_vue_no_silent_autosave_catches(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/Idt/RunMeeting.vue'));

        // Old silent comments are gone.
        $this->assertStringNotContainsString('Non-blocking auto-save failure', $vue);
        $this->assertStringNotContainsString('// Non-blocking', $vue);

        // Error refs exist.
        $this->assertStringContainsString('reviewSaveError', $vue);
        $this->assertStringContainsString('minutesSaveError', $vue);

        // Both error pills render with data-testids.
        $this->assertStringContainsString('data-testid="idt-review-save-error"', $vue);
        $this->assertStringContainsString('data-testid="idt-minutes-save-error"', $vue);
    }
}
