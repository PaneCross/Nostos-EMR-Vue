<?php

// ─── StaffCredentialSeeder ────────────────────────────────────────────────────
// Seeds realistic credentials + training records for every active staff user
// so the credentials UI and IT admin dashboard widget show meaningful data.
//
// Strategy:
//   - All staff: TB clearance (last 12 months) + HIPAA training (past year)
//   - Clinical depts (primary_care, pharmacy, therapies): add a professional
//     license with a mix of expiration dates (some current, some expiring
//     within 60 days, one or two expired for demo realism)
//   - Activities / dietary / social_work / behavioral_health: certifications
//     specific to their role
// Phase 4 (MVP roadmap).
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\StaffCredential;
use App\Models\StaffTrainingRecord;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class StaffCredentialSeeder extends Seeder
{
    /**
     * Department → professional license map. First element is label, second is
     * common state (we use the tenant's first user's site to infer, fallback CA).
     */
    private const LICENSE_BY_DEPT = [
        'primary_care'      => 'MD / DO License',
        'pharmacy'          => 'Pharmacist License (RPh)',
        'therapies'         => 'PT License',
        'social_work'       => 'LCSW License',
        'behavioral_health' => 'LCSW / LMFT License',
        'home_care'         => 'RN License',
        'idt'               => 'RN License',
    ];

    private const CERT_BY_DEPT = [
        'activities'        => ['CTRS Certification', 'Recreation Therapy'],
        'dietary'           => ['Registered Dietitian (RD)', 'Dietary / Nutrition'],
        'behavioral_health' => ['LCSW Certification',        'Mental Health Clinician'],
        'pharmacy'          => ['Immunization Certification','Immunization'],
        'idt'               => ['BLS Certification',         'Basic Life Support'],
        'primary_care'      => ['BLS Certification',         'Basic Life Support'],
        'home_care'         => ['BLS Certification',         'Basic Life Support'],
        'therapies'         => ['BLS Certification',         'Basic Life Support'],
    ];

    public function run(): void
    {
        $users = User::where('is_active', true)
            ->whereNotIn('department', ['super_admin'])
            ->get();

        if ($users->isEmpty()) {
            $this->command?->warn('No active staff — skipping credential seed.');
            return;
        }

        // Reuse the first IT admin / super admin in each tenant as the verifier
        // (the person who would have marked these as verified in prod).
        $verifierByTenant = [];
        foreach ($users->groupBy('tenant_id') as $tenantId => $_) {
            $verifierByTenant[$tenantId] = User::where('tenant_id', $tenantId)
                ->whereIn('department', ['it_admin', 'super_admin'])
                ->value('id')
                ?? User::where('tenant_id', $tenantId)->value('id');
        }

        $created = ['credentials' => 0, 'training' => 0];

        foreach ($users as $u) {
            $verifier = $verifierByTenant[$u->tenant_id] ?? $u->id;

            // ── Universal: TB clearance ──────────────────────────────────
            $tbIssued = $this->randomDateInPast(300, 450);
            StaffCredential::create([
                'tenant_id'           => $u->tenant_id,
                'user_id'             => $u->id,
                'credential_type'     => StaffCredential::TYPE_TB_CLEARANCE,
                'title'               => 'Annual TB Clearance',
                'issued_at'           => $tbIssued,
                'expires_at'          => $tbIssued->copy()->addYear(),
                'verified_at'         => now()->subMonths(mt_rand(1, 6)),
                'verified_by_user_id' => $verifier,
            ]);
            $created['credentials']++;

            // ── Professional license (some clinical depts) ───────────────
            if (isset(self::LICENSE_BY_DEPT[$u->department])) {
                // Skew expiration: 60% current (>180d), 30% expiring <60d, 10% expired
                $roll = mt_rand(1, 100);
                [$expires, $issued] = match (true) {
                    $roll <= 60 => [Carbon::now()->addDays(mt_rand(200, 700)), Carbon::now()->subDays(mt_rand(400, 1500))],
                    $roll <= 90 => [Carbon::now()->addDays(mt_rand(5, 55)),    Carbon::now()->subDays(mt_rand(300, 700))],
                    default     => [Carbon::now()->subDays(mt_rand(5, 45)),   Carbon::now()->subDays(mt_rand(400, 900))],
                };

                StaffCredential::create([
                    'tenant_id'           => $u->tenant_id,
                    'user_id'             => $u->id,
                    'credential_type'     => StaffCredential::TYPE_LICENSE,
                    'title'               => self::LICENSE_BY_DEPT[$u->department],
                    'license_state'       => 'CA',
                    'license_number'      => 'CA-' . strtoupper(substr(md5((string) $u->id), 0, 7)),
                    'issued_at'           => $issued->toDateString(),
                    'expires_at'          => $expires->toDateString(),
                    'verified_at'         => now()->subMonths(mt_rand(1, 6)),
                    'verified_by_user_id' => $verifier,
                ]);
                $created['credentials']++;
            }

            // ── Dept-specific certification ──────────────────────────────
            if (isset(self::CERT_BY_DEPT[$u->department])) {
                [$title, $_] = self::CERT_BY_DEPT[$u->department];
                $certIssued = $this->randomDateInPast(180, 540);
                StaffCredential::create([
                    'tenant_id'           => $u->tenant_id,
                    'user_id'             => $u->id,
                    'credential_type'     => StaffCredential::TYPE_CERTIFICATION,
                    'title'               => $title,
                    'issued_at'           => $certIssued,
                    'expires_at'          => $certIssued->copy()->addYears(2),
                    'verified_at'         => now()->subMonths(mt_rand(1, 6)),
                    'verified_by_user_id' => $verifier,
                ]);
                $created['credentials']++;
            }

            // ── Universal: annual flu shot (current year) ────────────────
            $fluDate = Carbon::create(now()->year - 1, 10, mt_rand(1, 28));
            StaffCredential::create([
                'tenant_id'           => $u->tenant_id,
                'user_id'             => $u->id,
                'credential_type'     => StaffCredential::TYPE_IMMUNIZATION,
                'title'               => 'Annual Flu Vaccination',
                'issued_at'           => $fluDate->toDateString(),
                'expires_at'          => $fluDate->copy()->addYear()->toDateString(),
                'verified_at'         => now()->subMonths(mt_rand(1, 6)),
                'verified_by_user_id' => $verifier,
            ]);
            $created['credentials']++;

            // ── Training: HIPAA annual + 2-3 others over past year ───────
            $trainings = [
                ['name' => 'Annual HIPAA Privacy Refresher', 'cat' => 'hipaa',             'hours' => 1.0],
                ['name' => 'Bloodborne Pathogens',           'cat' => 'infection_control', 'hours' => 1.0],
                ['name' => 'Fire & Emergency Procedures',    'cat' => 'fire_safety',       'hours' => 0.5],
            ];
            // Add direct-care for clinical staff
            if (in_array($u->department, ['primary_care', 'home_care', 'idt', 'therapies', 'pharmacy'], true)) {
                $trainings[] = ['name' => 'Abuse / Neglect Recognition', 'cat' => 'abuse_neglect',  'hours' => 1.0];
                $trainings[] = ['name' => 'Dementia Care Best Practices','cat' => 'dementia_care',  'hours' => 1.5];
                $trainings[] = ['name' => 'Fall Prevention',             'cat' => 'direct_care',    'hours' => 1.0];
            }

            foreach ($trainings as $t) {
                StaffTrainingRecord::create([
                    'tenant_id'           => $u->tenant_id,
                    'user_id'             => $u->id,
                    'training_name'       => $t['name'],
                    'category'            => $t['cat'],
                    'training_hours'      => $t['hours'],
                    'completed_at'        => $this->randomDateInPast(30, 330),
                    'verified_at'         => now()->subMonths(mt_rand(1, 6)),
                    'verified_by_user_id' => $verifier,
                ]);
                $created['training']++;
            }
        }

        $this->command?->info("Seeded staff credentials: {$created['credentials']} credentials, {$created['training']} training records.");
    }

    private function randomDateInPast(int $minDays, int $maxDays): Carbon
    {
        return Carbon::now()->subDays(mt_rand($minDays, $maxDays));
    }
}
