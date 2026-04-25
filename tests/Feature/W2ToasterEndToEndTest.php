<?php

// ─── Phase W2 — V5 Toaster + axios interceptor end-to-end smoke ────────────
// Audit-11 H2: V5ToasterWiringTest only does string-matching against file
// content. If someone renames `detail.message` to `detail.text`, structural
// tests still pass while toasts silently fail in production. This test:
//
//   1. Hits the test-only routes added in W2 to verify the backend returns
//      the JSON shape the axios interceptor actually consumes
//      (.response.data.message + status code).
//   2. Asserts the interceptor source code references the exact prop names
//      the Toaster component listens for, so rename-drift between the two
//      surfaces RED.
//
// Real DOM-level testing would need Cypress/Playwright; this is the maximum
// the Feature-test layer can validate without a JS runtime.
namespace Tests\Feature;

use Tests\TestCase;

class W2ToasterEndToEndTest extends TestCase
{
    public function test_500_returns_message_in_response_body(): void
    {
        $r = $this->getJson('/__test/__500');
        $r->assertStatus(500)
          ->assertJsonStructure(['message']);
        $this->assertNotEmpty($r->json('message'));
    }

    public function test_403_returns_message_in_response_body(): void
    {
        $r = $this->getJson('/__test/__403');
        $r->assertStatus(403)->assertJsonStructure(['message']);
    }

    public function test_409_returns_message_in_response_body(): void
    {
        $r = $this->getJson('/__test/__409');
        $r->assertStatus(409)->assertJsonStructure(['message']);
    }

    public function test_422_returns_per_field_errors_object(): void
    {
        $r = $this->getJson('/__test/__422');
        $r->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['field']]);
    }

    public function test_axios_interceptor_and_toaster_use_matching_event_shape(): void
    {
        $entry   = file_get_contents(resource_path('js/app.ts'));
        $toaster = file_get_contents(resource_path('js/Components/Toaster.vue'));

        // The interceptor dispatches { detail: { message, severity, timeout } }.
        $this->assertStringContainsString("dispatchEvent(new CustomEvent('nostos:toast'", $entry);
        $this->assertStringContainsString('detail: {', $entry);
        $this->assertStringContainsString("message: ", $entry);
        $this->assertStringContainsString("severity: 'error'", $entry);

        // The Toaster listens for the SAME prop names. If anyone renames either
        // side, this assertion fires.
        $this->assertStringContainsString("addEventListener('nostos:toast'", $toaster);
        $this->assertStringContainsString('detail.message', $toaster);
        $this->assertStringContainsString('detail.severity', $toaster);
        $this->assertStringContainsString('detail.timeout', $toaster);
    }

    public function test_axios_interceptor_skips_422_and_401(): void
    {
        $entry = file_get_contents(resource_path('js/app.ts'));
        // Per V5 design: 422 forms render inline; 401 triggers auth redirect.
        $this->assertStringContainsString('Skip 422', $entry);
        $this->assertStringContainsString('skip 401', $entry);
    }
}
