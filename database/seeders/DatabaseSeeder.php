<?php

// ─── DatabaseSeeder ───────────────────────────────────────────────────────────
// Laravel's default top-level seeder. `php artisan db:seed` runs this; it just
// delegates to DemoEnvironmentSeeder, which orchestrates every other seeder
// in the correct dependency order.
//
// When to run: dev / demo only. Production tenants should run only the
// foundational reference seeders (Permission, SystemNoteTemplate, CarcCode,
// Icd10, MedicationsReference, DrugLabInteraction, BeersCriteria, etc.) — NOT
// this one, which fabricates patient data.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoEnvironmentSeeder::class);
    }
}
