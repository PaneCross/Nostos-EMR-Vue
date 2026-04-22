<?php

// ─── Migration: Staff Training Records ────────────────────────────────────────
// Per 42 CFR §460.71, PACE staff must receive orientation + ongoing training.
// Certain direct-care training has an annual-hour minimum (state-specific).
// This table stores each discrete training event (course completion, in-service,
// in-person workshop, etc.) separately from credentials so hours can be totaled.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_staff_training_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('shared_users')->cascadeOnDelete();

            $table->string('training_name', 200);
            $table->string('category', 40);              // direct_care | hipaa | infection_control | dementia_care | abuse_neglect | fire_safety | other
            $table->decimal('training_hours', 5, 2);     // e.g. 1.50
            $table->date('completed_at');

            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();

            $table->string('document_path', 500)->nullable();
            $table->string('document_filename', 255)->nullable();

            $table->text('notes')->nullable();

            $table->timestampsTz();
            $table->softDeletes();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'user_id', 'completed_at'], 'emr_staff_training_completed_idx');
        });

        DB::statement("
            ALTER TABLE emr_staff_training_records
            ADD CONSTRAINT emr_staff_training_category_check
            CHECK (category IN (
                'direct_care',
                'hipaa',
                'infection_control',
                'dementia_care',
                'abuse_neglect',
                'fire_safety',
                'orientation',
                'clinical',
                'compliance',
                'other'
            ))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_staff_training_records');
    }
};
