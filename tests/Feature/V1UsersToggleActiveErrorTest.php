<?php

// ─── Phase V1 — Users.vue surfaces errors instead of silently swallowing ────
// Audit-10 found Users.vue called toggleActive() with .catch(() => {}) — the
// row state would optimistically flip but a server-side 4xx would never reach
// the UI. Wave V1 added per-row error surfacing.
//
// Surface mechanism MOVED in the row-click-modal refactor (commit 01b0398).
// The V1 row-error pill was replaced with an in-modal error banner that
// catches every modal-action failure (toggleActive, toggleDesignation,
// resetAccess) and renders it inside the user-detail modal. This test now
// asserts the new mechanism — same regression-trap intent (errors surfaced,
// not swallowed) by a different implementation.
namespace Tests\Feature;

use Tests\TestCase;

class V1UsersToggleActiveErrorTest extends TestCase
{
    public function test_users_vue_surfaces_errors_via_modal_detail_error_banner(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/ItAdmin/Users.vue'));

        // No silent-swallow patterns left.
        $this->assertStringNotContainsString('.catch(() => {})', $vue);
        $this->assertStringNotContainsString('// silently handle', $vue);

        // Modal carries a detailError ref that every action writes to on failure.
        $this->assertStringContainsString('const detailError = ref<string | null>', $vue);

        // toggleActive, toggleDesignation, and resetAccess all set detailError on catch.
        $this->assertGreaterThanOrEqual(3, substr_count($vue, 'detailError.value ='));

        // Template renders the error banner with role=alert (V5 axios pattern).
        $this->assertStringContainsString('data-testid="user-detail-error"', $vue);
        $this->assertStringContainsString("role=\"alert\"", $vue);
    }
}
