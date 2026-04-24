<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('emr_participants', function (Blueprint $t) {
            $t->foreignId('primary_care_user_id')->nullable()
                ->after('site_id')
                ->constrained('shared_users')->nullOnDelete();
            $t->index(['tenant_id', 'primary_care_user_id'], 'emr_participants_pcp_idx');
        });
    }

    public function down(): void
    {
        Schema::table('emr_participants', function (Blueprint $t) {
            $t->dropIndex('emr_participants_pcp_idx');
            $t->dropConstrainedForeignId('primary_care_user_id');
        });
    }
};
