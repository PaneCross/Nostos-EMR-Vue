<?php

// ─── Phase V5 — Global Toaster + axios interceptor wiring ──────────────────
namespace Tests\Feature;

use Tests\TestCase;

class V5ToasterWiringTest extends TestCase
{
    public function test_toaster_component_exists(): void
    {
        $this->assertFileExists(resource_path('js/Components/Toaster.vue'));
        $vue = file_get_contents(resource_path('js/Components/Toaster.vue'));
        $this->assertStringContainsString("'nostos:toast'", $vue);
        $this->assertStringContainsString('data-testid="toaster"', $vue);
    }

    public function test_app_shell_mounts_toaster(): void
    {
        $shell = file_get_contents(resource_path('js/Layouts/AppShell.vue'));
        $this->assertStringContainsString("import Toaster from '@/Components/Toaster.vue'", $shell);
        $this->assertStringContainsString('<Toaster />', $shell);
    }

    public function test_axios_interceptor_dispatches_toast_event(): void
    {
        $entry = file_get_contents(resource_path('js/app.ts'));
        $this->assertStringContainsString("dispatchEvent(new CustomEvent('nostos:toast'", $entry);
        // Skip 422 (validation handled per-component) + 401 (auth redirect).
        $this->assertStringContainsString('Skip 422', $entry);
    }
}
