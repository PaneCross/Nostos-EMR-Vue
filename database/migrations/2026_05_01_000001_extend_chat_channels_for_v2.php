<?php

// ─── Migration: extend emr_chat_channels for Chat v2 ─────────────────────────
// Adds two new channel_type values (role_group, group_dm), plus new columns
// description and site_wide. The original channel_type was a Postgres enum ;
// adding values to a Postgres enum is awkward (you can't drop them in down()
// and you can't easily add multiple values across migrations), so we convert
// to varchar(20) + a CHECK constraint which is freely editable.
//
// See docs/plans/chat_v2_plan.md §3.1 for the full design.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Postgres enums are types ; converting to varchar drops the type-
        // level enum check but Laravel may have created a column-level CHECK
        // with the same conventional name. Drop it defensively before adding
        // ours.
        DB::statement("ALTER TABLE emr_chat_channels DROP CONSTRAINT IF EXISTS emr_chat_channels_channel_type_check");
        DB::statement("ALTER TABLE emr_chat_channels ALTER COLUMN channel_type TYPE varchar(20) USING channel_type::text");

        // Add the v2 channel types.
        DB::statement("ALTER TABLE emr_chat_channels ADD CONSTRAINT emr_chat_channels_channel_type_check
            CHECK (channel_type IN ('direct', 'department', 'participant_idt', 'broadcast', 'role_group', 'group_dm'))");

        Schema::table('emr_chat_channels', function (Blueprint $t) {
            // Optional channel topic / purpose, shown in channel header.
            $t->string('description', 500)->nullable()->after('name');
            // True for role-group channels that target the entire tenant.
            // False (default) when targeted to specific departments.
            $t->boolean('site_wide')->default(false)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('emr_chat_channels', function (Blueprint $t) {
            $t->dropColumn(['description', 'site_wide']);
        });

        DB::statement("ALTER TABLE emr_chat_channels DROP CONSTRAINT IF EXISTS emr_chat_channels_channel_type_check");

        // Restore the original 4-value CHECK so a future re-up() works.
        DB::statement("ALTER TABLE emr_chat_channels ADD CONSTRAINT emr_chat_channels_channel_type_check
            CHECK (channel_type IN ('direct', 'department', 'participant_idt', 'broadcast'))");
    }
};
