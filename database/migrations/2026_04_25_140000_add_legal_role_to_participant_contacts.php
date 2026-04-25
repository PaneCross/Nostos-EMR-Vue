<?php

// Phase S1 — Add structured legal_role + relationship_role to contacts.
// PACE buyers ask: "show me which contact is the durable POA / healthcare
// proxy / legal guardian / spouse." The pre-Wave-S contact_type enum does
// not separate legal role from family relationship. This migration adds:
//   - legal_role (durable_poa, healthcare_proxy, legal_guardian, court_appointed, none)
//   - relationship_role (spouse, parent, child, sibling, partner, friend, other)
// Both nullable; existing rows unaffected.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('emr_participant_contacts', function (Blueprint $t) {
            $t->string('legal_role', 30)->nullable()->after('is_legal_representative');
            $t->string('relationship_role', 30)->nullable()->after('legal_role');
        });

        DB::statement("ALTER TABLE emr_participant_contacts ADD CONSTRAINT emr_participant_contacts_legal_role_check
            CHECK (legal_role IN ('durable_poa','healthcare_proxy','legal_guardian','court_appointed','none')
                   OR legal_role IS NULL)");

        DB::statement("ALTER TABLE emr_participant_contacts ADD CONSTRAINT emr_participant_contacts_relationship_role_check
            CHECK (relationship_role IN ('spouse','partner','parent','child','sibling','grandchild','friend','other')
                   OR relationship_role IS NULL)");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE emr_participant_contacts DROP CONSTRAINT IF EXISTS emr_participant_contacts_legal_role_check");
        DB::statement("ALTER TABLE emr_participant_contacts DROP CONSTRAINT IF EXISTS emr_participant_contacts_relationship_role_check");

        Schema::table('emr_participant_contacts', function (Blueprint $t) {
            $t->dropColumn(['legal_role', 'relationship_role']);
        });
    }
};
