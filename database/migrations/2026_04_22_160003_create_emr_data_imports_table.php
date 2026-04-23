<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─── Phase 15.4 — Data migration toolkit ────────────────────────────────────
// Tracks each CSV upload: what entity, how many rows parsed, status, errors.
// Actual row inserts happen in DataImportService::commit, which runs row-by-
// row in a transaction so partial failures don't leave half-imported data.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_data_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('uploaded_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->string('entity', 40);       // participants|problems|allergies|medications|care_plans|enrollments|documents
            $t->string('status', 20)->default('staged'); // staged|committed|failed|cancelled
            $t->string('original_filename');
            $t->string('stored_path');
            $t->integer('parsed_row_count')->default(0);
            $t->integer('committed_row_count')->default(0);
            $t->integer('error_row_count')->default(0);
            $t->jsonb('column_mapping')->nullable(); // {csv_col → db_col}
            $t->jsonb('errors_json')->nullable();    // array of {row, message}
            $t->timestamp('staged_at')->nullable();
            $t->timestamp('committed_at')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status'], 'imports_tenant_status_idx');
        });

        \DB::statement("
            ALTER TABLE emr_data_imports
            ADD CONSTRAINT imports_status_check
            CHECK (status IN ('staged','committed','failed','cancelled'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_data_imports');
    }
};
