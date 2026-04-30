<?php

// ─── Migration: emr_chat_channel_department_targets ──────────────────────────
// Many-to-many : a role_group channel targets one or more departments.
// Empty for site_wide channels (channel.site_wide = true).
//
// Stored as the department slug (matches User.department) for the same
// reason job_title_code is stored as a string in role_targets : the auto-add
// observer compares directly against the user's column.
//
// See docs/plans/chat_v2_plan.md §3.3.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_chat_channel_department_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_id')
                ->constrained('emr_chat_channels')
                ->cascadeOnDelete();
            // Matches User.department (e.g. 'primary_care', 'social_work').
            $t->string('department', 30);
            $t->timestamp('created_at')->useCurrent();

            $t->unique(['channel_id', 'department']);
            $t->index('department');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_chat_channel_department_targets');
    }
};
