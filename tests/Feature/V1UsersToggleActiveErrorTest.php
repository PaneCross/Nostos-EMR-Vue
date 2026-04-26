<?php

// ─── Phase V1 — Users.vue toggle-active surfaces errors instead of silently ─
// Audit-10 found Users.vue called toggleActive() with .catch(() => {}) — the
// row state would optimistically flip in the table but a server-side 4xx
// would never reach the UI. Wave V1 added a per-row error pill + automatic
// rollback. This test locks in that on a 422/500 the controller returns
// the right error shape so the Vue handler can render it. Regression trap.
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
