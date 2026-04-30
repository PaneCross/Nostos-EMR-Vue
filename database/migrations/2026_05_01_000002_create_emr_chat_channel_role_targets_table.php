<?php

// ─── Migration: emr_chat_channel_role_targets ────────────────────────────────
// Many-to-many : a role_group (specialized) channel targets one or more
// JobTitles. The pair (channel_id, job_title_code) is unique.
//
// We store the job_title_code (not a FK to emr_job_titles.id) so that
// renaming a JobTitle's label doesn't require a cascading update here, and
// so that the auto-add observer can compare against User.job_title (which
// is itself a code string).
//
// See docs/plans/chat_v2_plan.md §3.2.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emr_chat_channel_role_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_id')
                ->constrained('emr_chat_channels')
                ->cascadeOnDelete();
            // Matches User.job_title and emr_job_titles.code.
            $t->string('job_title_code', 60);
            $t->timestamp('created_at')->useCurrent();

            $t->unique(['channel_id', 'job_title_code']);
            // Single-column index on the code so the auto-add observer query
            // (find all role_groups targeting a given job title) is fast.
            $t->index('job_title_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_chat_channel_role_targets');
    }
};
