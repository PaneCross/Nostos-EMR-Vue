<?php

// ─── Phase V1 — Users.vue toggle-active surfaces errors instead of silently ─
namespace Tests\Feature;

use Tests\TestCase;

class V1UsersToggleActiveErrorTest extends TestCase
{
    public function test_users_vue_surfaces_row_error_on_failure(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/ItAdmin/Users.vue'));

        // Empty silent-handle pattern is gone.
        $this->assertStringNotContainsString('// silently handle', $vue);

        // New rowError ref + showRowError helper exist.
        $this->assertStringContainsString('const rowError = ref<', $vue);
        $this->assertStringContainsString('function showRowError', $vue);

        // showRowError defined + called from both toggleActive and toggleDesignation.
        $this->assertGreaterThanOrEqual(3, substr_count($vue, 'showRowError'));

        // Template renders the error pill.
        $this->assertStringContainsString('data-testid="users-row-error"', $vue);
    }
}
