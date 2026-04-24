<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('emr_saved_dashboards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('shared_tenants')->cascadeOnDelete();
            $t->foreignId('owner_user_id')->constrained('shared_users')->cascadeOnDelete();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->jsonb('widgets'); // array of widget configs
            $t->boolean('is_shared')->default(false);
            $t->timestamps();

            $t->index(['tenant_id', 'owner_user_id'], 'dashboards_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emr_saved_dashboards');
    }
};
