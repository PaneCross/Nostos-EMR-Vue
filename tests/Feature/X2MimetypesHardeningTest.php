<?php

// ─── Phase X2 — Document + photo upload validate by real MIME (Audit-12 H2)
namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class X2MimetypesHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function authedTenantUser(string $dept = 'primary_care'): array
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => strtoupper(Str::random(3))]);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => $dept, 'role' => 'admin', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        return [$t, $u, $p];
    }

    public function test_document_upload_rejects_html_file_renamed_to_pdf(): void
    {
        Storage::fake('local');
        [$t, $u, $p] = $this->authedTenantUser();

        // HTML payload labeled as .pdf with declared text/html MIME — extension
        // matches the old mimes: rule but mimetypes: enforces real MIME type.
        $file = UploadedFile::fake()->create('evil.pdf', 1, 'text/html');

        $r = $this->actingAs($u)->post("/participants/{$p->id}/documents", [
            'file'              => $file,
            'document_category' => 'consent',
            'description'       => 'attempted bypass',
        ]);
        $r->assertStatus(302); // Inertia validation redirect
        $r->assertSessionHasErrors('file');
    }

    public function test_document_upload_request_uses_mimetypes_rule(): void
    {
        $request = file_get_contents(app_path('Http/Requests/StoreDocumentRequest.php'));
        $this->assertStringContainsString('mimetypes:', $request);
        $this->assertStringNotContainsString("'mimes:pdf,jpeg,jpg,png,docx'", $request,
            'StoreDocumentRequest must not rely on extension-based mimes: rule.');
    }

    public function test_photo_upload_uses_mimetypes_rule(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/ParticipantController.php'));
        $this->assertStringContainsString("mimetypes:image/jpeg,image/png,image/webp", $controller);
        $this->assertStringNotContainsString("'mimes:jpg,jpeg,png,webp'", $controller,
            'uploadPhoto must not rely on extension-based mimes: rule.');
    }
}
