<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase B7 — Note templates library ──────────────────────────────────────
// System defaults have tenant_id=NULL (accessible to all tenants). Per-tenant
// overrides/additions have tenant_id set. NoteTemplateService merges the two.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_note_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained('shared_tenants')->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('note_type', 50); // matches ClinicalNote::NOTE_TYPES
            $t->string('department', 40)->nullable();
            $t->text('body_markdown'); // Markdown with {{variable}} placeholders
            $t->boolean('is_system')->default(false);
            $t->foreignId('created_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->timestamps();

            // For system defaults, tenant_id NULL + name must still be unique.
            $t->index(['tenant_id', 'note_type'], 'note_templates_tenant_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_note_templates');
    }
};
