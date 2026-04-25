<?php

// ─── Phase W1 — Stuck-saving regression guard ──────────────────────────────
// Audit-11 H1 found 5 participant tabs with `try { saving=true; ... }
// catch { saving=false }` but no `finally`, leaving the submit button stuck
// disabled after the first successful save + reopen. This test ensures the
// `finally { saving.value = false }` pattern remains in each.
namespace Tests\Feature;

use Tests\TestCase;

class W1StuckSavingRegressionTest extends TestCase
{
    /**
     * @dataProvider stuckSavingTabsProvider
     */
    public function test_tab_has_finally_saving_false(string $relativePath): void
    {
        $vue = file_get_contents(resource_path("js/{$relativePath}"));

        // The faulty pattern was catch{} setting saving=false with no finally.
        // The fix puts `saving.value = false` inside `finally {}`. Assert both are present.
        $this->assertStringContainsString('} finally {', $vue,
            "{$relativePath} must use try/catch/finally with saving.value=false in finally.");
        // Allow any whitespace / comments between `finally {` and the reset line.
        $this->assertMatchesRegularExpression(
            '/finally\s*{[^}]*saving\.value\s*=\s*false/s',
            $vue,
            "{$relativePath} finally block must reset saving.value = false."
        );
    }

    public static function stuckSavingTabsProvider(): array
    {
        return [
            'allergies'    => ['Pages/Participants/Tabs/AllergiesTab.vue'],
            'problems'     => ['Pages/Participants/Tabs/ProblemsTab.vue'],
            'contacts'     => ['Pages/Participants/Tabs/ContactsTab.vue'],
            'immunizations'=> ['Pages/Participants/Tabs/ImmunizationsTab.vue'],
            'transfers'    => ['Pages/Participants/Tabs/TransfersTab.vue'],
        ];
    }
}
