<?php

// ─── Phase W4 — preserveScroll on Inertia useForm submissions ──────────────
namespace Tests\Feature;

use Tests\TestCase;

class W4PreserveScrollTest extends TestCase
{
    public function test_system_settings_submit_uses_preserve_scroll(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/ItAdmin/SystemSettings.vue'));

        // The form.put call must include preserveScroll: true so a 422 doesn't
        // jump the user to the page top in the middle of editing.
        $this->assertStringContainsString('preserveScroll: true', $vue,
            'SystemSettings.vue form.put must include preserveScroll: true (Audit-11 M2).');
        $this->assertStringContainsString('preserveState: true', $vue,
            'SystemSettings.vue form.put must include preserveState: true to keep typed input visible.');
        $this->assertStringContainsString('Audit-11 M2', $vue);
    }

    public function test_documents_pattern_for_future_useForm_consumers(): void
    {
        // Spot-check: any existing useForm consumer should follow the convention
        // (or have a deliberate reason not to). Just enforce SystemSettings here;
        // Tasks/Index.vue already uses preserveScroll on filter changes.
        $tasks = file_get_contents(resource_path('js/Pages/Tasks/Index.vue'));
        $this->assertStringContainsString('preserveScroll', $tasks,
            'Tasks/Index.vue still uses preserveScroll on filter changes.');
    }
}
