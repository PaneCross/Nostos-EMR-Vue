<?php

// ─── Phase V3 — CmsAuditUniverses Generate uses axios + surfaces 409/422 ───
namespace Tests\Feature;

use Tests\TestCase;

class V3CmsUniverseGenerateTest extends TestCase
{
    public function test_cms_universes_vue_uses_axios_with_blob(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/Compliance/CmsAuditUniverses.vue'));

        // Old bare <a :href="downloadUrl(...)"> pattern is gone.
        $this->assertStringNotContainsString('downloadUrl(', $vue);

        // New axios.get with blob responseType present.
        $this->assertStringContainsString("axios.get(url, { responseType: 'blob' })", $vue);

        // 409 max-attempts handled with explicit messaging.
        $this->assertStringContainsString('Maximum 3 attempts reached', $vue);
        $this->assertStringContainsString('Validation failed:', $vue);

        // Card error pill renders with data-testid.
        $this->assertStringContainsString('data-testid="cms-universe-error"', $vue);
        $this->assertStringContainsString('data-testid="cms-universe-generate"', $vue);
    }
}
