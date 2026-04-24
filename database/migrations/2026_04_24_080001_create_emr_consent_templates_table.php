<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_consent_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained('shared_tenants')->cascadeOnDelete();
            $t->string('consent_type', 40); // matches ConsentRecord::CONSENT_TYPES
            $t->string('version', 30);       // e.g. "2026.04-v1"
            $t->string('title', 200);
            $t->text('body');
            $t->string('status', 20)->default('draft'); // draft|approved|archived
            $t->foreignId('approved_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'consent_type', 'version'], 'consent_templates_uniq');
            $t->index(['tenant_id', 'consent_type', 'status'], 'consent_templates_active_idx');
        });

        DB::statement("ALTER TABLE emr_consent_templates ADD CONSTRAINT consent_templates_status_chk
            CHECK (status IN ('draft','approved','archived'))");

        Schema::table('emr_consent_records', function (Blueprint $t) {
            $t->foreignId('consent_template_id')->nullable()
                ->after('document_version')
                ->constrained('emr_consent_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('emr_consent_records', function (Blueprint $t) {
            $t->dropConstrainedForeignId('consent_template_id');
        });
        Schema::dropIfExists('emr_consent_templates');
    }
};
