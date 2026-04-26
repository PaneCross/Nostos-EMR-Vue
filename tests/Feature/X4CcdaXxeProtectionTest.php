<?php

// ─── Phase X4 — CCDA importer LIBXML_NONET protects against XXE ────────────
namespace Tests\Feature;

use App\Services\CcdaImportService;
use Tests\TestCase;

class X4CcdaXxeProtectionTest extends TestCase
{
    public function test_ccda_import_uses_libxml_nonet(): void
    {
        $svc = file_get_contents(app_path('Services/CcdaImportService.php'));
        $this->assertStringContainsString('LIBXML_NONET', $svc,
            'CcdaImportService must call DOMDocument::loadXML with LIBXML_NONET to prevent XXE.');
        $this->assertStringContainsString('Audit-12 M1', $svc,
            'Phase X4 marker comment must reference the audit finding.');
    }

    public function test_ccda_xxe_payload_does_not_resolve_external_entity(): void
    {
        // Construct a malicious CCDA-like XML that defines an external entity
        // pointing at a file the importer should never read. With LIBXML_NONET
        // (and entity substitution off), the patient first name should NOT
        // contain the file's contents.
        $xxe = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE foo [
  <!ENTITY xxe SYSTEM "file:///etc/passwd">
]>
<ClinicalDocument xmlns="urn:hl7-org:v3">
  <recordTarget>
    <patientRole>
      <patient>
        <name>
          <given>&xxe;</given>
          <family>Test</family>
        </name>
      </patient>
    </patientRole>
  </recordTarget>
</ClinicalDocument>
XML;

        $svc = new CcdaImportService();
        $result = $svc->parse($xxe);

        $first = $result['patient']['first_name'] ?? '';
        // Without entity resolution, the given name should be empty (entity
        // didn't expand) — definitely not contain "root:" or any file content.
        $this->assertStringNotContainsString('root:', $first,
            'XXE payload was resolved — CCDA importer is vulnerable.');
        $this->assertStringNotContainsString('/bin/', $first);
    }
}
