<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_staff_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('participant_id')->nullable()->constrained('emr_participants')->nullOnDelete();
            $t->foreignId('assigned_to_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->string('assigned_to_department', 40)->nullable();
            $t->foreignId('created_by_user_id')->nullable()->constrained('shared_users')->nullOnDelete();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('priority', 20)->default('normal'); // low|normal|high|urgent
            $t->timestamp('due_at')->nullable();
            $t->string('status', 20)->default('pending'); // pending|in_progress|completed|cancelled
            $t->timestamp('completed_at')->nullable();
            $t->text('completion_note')->nullable();
            // Polymorphic link to the originating resource (grievance, appeal, etc.)
            $t->string('related_to_type', 60)->nullable();
            $t->unsignedBigInteger('related_to_id')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status', 'due_at'], 'tasks_queue_idx');
            $t->index(['tenant_id', 'assigned_to_user_id', 'status'], 'tasks_user_queue_idx');
            $t->index(['tenant_id', 'assigned_to_department', 'status'], 'tasks_dept_queue_idx');
            $t->index(['related_to_type', 'related_to_id'], 'tasks_related_idx');
        });

        DB::statement("ALTER TABLE emr_staff_tasks ADD CONSTRAINT tasks_priority_chk
            CHECK (priority IN ('low','normal','high','urgent'))");
        DB::statement("ALTER TABLE emr_staff_tasks ADD CONSTRAINT tasks_status_chk
            CHECK (status IN ('pending','in_progress','completed','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_staff_tasks');
    }
};
