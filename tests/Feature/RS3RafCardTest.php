<?php

// ─── Phase RS3 — InsuranceTab consumes /raf-snapshot ───────────────────────
namespace Tests\Feature;

use Tests\TestCase;

class RS3RafCardTest extends TestCase
{
    public function test_insurance_tab_fetches_raf_snapshot(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/Participants/Tabs/InsuranceTab.vue'));
        $this->assertStringContainsString('/raf-snapshot', $vue);
        $this->assertStringContainsString('data-testid="raf-card"', $vue);
        $this->assertStringContainsString('CMS-HCC V28', $vue) ?: $this->assertStringContainsString('model_label', $vue);
    }
}
