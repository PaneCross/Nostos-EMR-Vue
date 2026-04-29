<?php

// ─── AssignDemoJobTitlesSeeder ───────────────────────────────────────────────
// Assigns realistic job_title + supervisor links to existing demo users so
// the credentials targeting + escalation chain work meaningfully in demo.
//
// Mapping logic per (department, role):
//   primary_care + admin       → md          (the medical director)
//   primary_care + standard    → np          (mid-level provider)
//   pharmacy + admin           → pharmacist
//   pharmacy + standard        → pharm_tech
//   therapies + admin          → ot          (rotates with pt)
//   therapies + standard       → pt
//   social_work + admin        → lcsw
//   social_work + standard     → msw
//   home_care + admin          → rn          (home care RN supervisor)
//   home_care + standard       → home_care_aide
//   transportation + admin     → driver      (still drives + manages)
//   transportation + standard  → driver
//   dietary + admin            → rd
//   dietary + standard         → other
//   activities                 → recreation_aide
//   behavioral_health + admin  → lcsw
//   behavioral_health + std    → msw
//   idt + admin                → rn
//   idt + standard             → rn
//   enrollment / finance / qa  → admin_assistant / other (non-credentialed roles)
//
// Also wires supervisor_user_id : standard users in clinical depts get pointed
// to their dept's admin user.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AssignDemoJobTitlesSeeder extends Seeder
{
    public const JOB_TITLE_MAP = [
        'primary_care'       => ['admin' => 'md',          'standard' => 'np'],
        'pharmacy'           => ['admin' => 'pharmacist',  'standard' => 'pharm_tech'],
        'therapies'          => ['admin' => 'ot',          'standard' => 'pt'],
        'social_work'        => ['admin' => 'lcsw',        'standard' => 'msw'],
        'home_care'          => ['admin' => 'rn',          'standard' => 'home_care_aide'],
        'transportation'     => ['admin' => 'driver',      'standard' => 'driver'],
        'dietary'            => ['admin' => 'rd',          'standard' => 'other'],
        'activities'         => ['admin' => 'recreation_aide', 'standard' => 'recreation_aide'],
        'behavioral_health'  => ['admin' => 'lcsw',        'standard' => 'msw'],
        'idt'                => ['admin' => 'rn',          'standard' => 'rn'],
        'enrollment'         => ['admin' => 'admin_assistant', 'standard' => 'admin_assistant'],
        'finance'            => ['admin' => 'admin_assistant', 'standard' => 'admin_assistant'],
        'qa_compliance'      => ['admin' => 'admin_assistant', 'standard' => 'admin_assistant'],
        'it_admin'           => ['admin' => 'other', 'standard' => 'other', 'super_admin' => 'other'],
        'executive'          => ['admin' => 'center_manager', 'standard' => 'center_manager'],
    ];

    public function run(): void
    {
        // B4 : ensure each tenant has at least one demo executive user so the
        // executive code-paths (catalog edit, dashboard view, job-titles
        // management, org-settings) can be exercised without falling back to
        // Super Admin.
        \App\Models\Tenant::all()->each(function ($tenant) {
            $exists = User::where('tenant_id', $tenant->id)
                ->where('department', 'executive')
                ->where('is_active', true)
                ->exists();
            if (! $exists) {
                User::factory()->create([
                    'tenant_id'  => $tenant->id,
                    'first_name' => 'Vivian',
                    'last_name'  => 'Executive',
                    'email'      => "exec.demo.{$tenant->id}@nostos-demo.test",
                    'department' => 'executive',
                    'role'       => 'admin',
                    'job_title'  => 'center_manager',
                    'is_active'  => true,
                ]);
            }
        });

        $users = User::where('is_active', true)->get();

        // Pass 1 : assign job_title
        foreach ($users as $u) {
            $title = self::JOB_TITLE_MAP[$u->department][$u->role] ?? null;
            if ($title && ! $u->job_title) {
                $u->update(['job_title' => $title]);
            }
        }

        // Pass 2 : assign supervisors. For each dept, find an admin user ; assign
        // them as supervisor for all 'standard' users in same dept + tenant.
        $byTenantDept = $users->groupBy(fn ($u) => "{$u->tenant_id}|{$u->department}");

        foreach ($byTenantDept as $key => $group) {
            $admin = $group->firstWhere('role', 'admin');
            if (! $admin) continue;

            foreach ($group as $u) {
                if ($u->id === $admin->id) continue;
                if ($u->role !== 'standard') continue;
                if ($u->supervisor_user_id) continue;
                $u->update(['supervisor_user_id' => $admin->id]);
            }
        }

        // The exec / it_admin admins themselves don't need supervisors set
        // (they're at the top of the chain). Nothing to do for them.
    }
}
