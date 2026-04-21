<?php

// ─── W54DataSeeder ─────────────────────────────────────────────────────────
// Demo data for W5-4: Tab CRUD Verification.
//
// Seeds realistic demo data for participant profile tabs:
//   - Consent records (NPP + HIPAA authorization + treatment consent)
//   - Documents (dummy placeholder files in storage)
//   - Immunizations (flu, pneumococcal, COVID-19, zoster)
//   - Procedures (EKG, DEXA, colonoscopy)
//   - SDOH screenings (baseline + 6-month follow-up per participant)
//
// Seeds for first 8 enrolled participants.
// Called from DemoEnvironmentSeeder after W52DataSeeder.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\ConsentRecord;
use App\Models\Document;
use App\Models\Immunization;
use App\Models\Participant;
use App\Models\Procedure;
use App\Models\SocialDeterminant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class W54DataSeeder extends Seeder
{
    public function run(): void
    {
        $participants = Participant::where('enrollment_status', 'enrolled')
            ->with('site')
            ->limit(8)
            ->get();

        if ($participants->isEmpty()) {
            $this->command->warn('  W54DataSeeder: No enrolled participants found — skipping.');
            return;
        }

        $tenantId = $participants->first()->tenant_id;

        $primaryCareUser = User::where('tenant_id', $tenantId)
            ->where('department', 'primary_care')
            ->first();
        $socialWorkUser = User::where('tenant_id', $tenantId)
            ->where('department', 'social_work')
            ->first();

        if (! $primaryCareUser) {
            $this->command->warn('  W54DataSeeder: No primary_care user found — skipping.');
            return;
        }

        $this->command->line('  W5-4 Tab demo data:');

        $consentCount = 0;
        $docCount     = 0;
        $immunCount   = 0;
        $procCount    = 0;
        $sdohCount    = 0;

        foreach ($participants as $participant) {

            // ── Consent Records ──────────────────────────────────────────────
            if (! ConsentRecord::where('participant_id', $participant->id)->exists()) {

                ConsentRecord::create([
                    'participant_id'     => $participant->id,
                    'tenant_id'          => $tenantId,
                    'consent_type'       => 'npp_acknowledgment',
                    'document_title'     => 'Notice of Privacy Practices v2024',
                    'document_version'   => '2024.1',
                    'status'             => 'acknowledged',
                    'acknowledged_by'    => $participant->first_name . ' ' . $participant->last_name,
                    'acknowledged_at'    => now()->subDays(rand(30, 365)),
                    'expiration_date'    => null,
                    'notes'              => 'Patient verbally confirmed receipt of NPP. Copy provided.',
                    'created_by_user_id' => $primaryCareUser->id,
                ]);

                ConsentRecord::create([
                    'participant_id'     => $participant->id,
                    'tenant_id'          => $tenantId,
                    'consent_type'       => 'hipaa_authorization',
                    'document_title'     => 'HIPAA Authorization for Release of Information',
                    'document_version'   => '2023.2',
                    'status'             => 'acknowledged',
                    'acknowledged_by'    => $participant->first_name . ' ' . $participant->last_name,
                    'acknowledged_at'    => now()->subDays(rand(60, 400)),
                    'expiration_date'    => now()->addYear(),
                    'notes'              => null,
                    'created_by_user_id' => $primaryCareUser->id,
                ]);

                $statusOptions = ['acknowledged', 'acknowledged', 'pending'];
                $status = $statusOptions[array_rand($statusOptions)];
                ConsentRecord::create([
                    'participant_id'     => $participant->id,
                    'tenant_id'          => $tenantId,
                    'consent_type'       => 'treatment_consent',
                    'document_title'     => 'General Consent for Treatment and Services',
                    'document_version'   => '2024.1',
                    'status'             => $status,
                    'acknowledged_by'    => $status === 'acknowledged' ? $participant->first_name . ' ' . $participant->last_name : null,
                    'acknowledged_at'    => $status === 'acknowledged' ? now()->subDays(rand(10, 200)) : null,
                    'expiration_date'    => now()->addYears(2),
                    'notes'              => $status === 'pending' ? 'Scheduled for signature at next center visit.' : null,
                    'created_by_user_id' => $primaryCareUser->id,
                ]);

                $consentCount += 3;
            }

            // ── Documents ────────────────────────────────────────────────────
            if (! Document::where('participant_id', $participant->id)->exists()) {
                $docs = [
                    ['file_name' => 'referral_authorization_2024.pdf',   'file_type' => 'pdf', 'file_size_bytes' => 124800,  'document_category' => 'referral',  'description' => 'Specialist referral — Cardiology'],
                    ['file_name' => 'insurance_card_scan.pdf',           'file_type' => 'pdf', 'file_size_bytes' => 87040,   'document_category' => 'insurance', 'description' => 'Medicare and Medicaid card scans'],
                    ['file_name' => 'advance_directive_signed.pdf',      'file_type' => 'pdf', 'file_size_bytes' => 215040,  'document_category' => 'consent',   'description' => 'Durable Power of Attorney for Healthcare'],
                    ['file_name' => 'pace_enrollment_packet.pdf',        'file_type' => 'pdf', 'file_size_bytes' => 342016,  'document_category' => 'other',     'description' => 'Signed PACE enrollment agreement and disclosures'],
                ];

                foreach ($docs as $doc) {
                    $path = 'participants/' . $participant->id . '/' . $doc['file_name'];
                    if (! Storage::exists($path)) {
                        Storage::put($path, "%PDF-1.4\n% Placeholder: " . $doc['file_name'] . "\n% NostosEMR demo data\n");
                    }
                    Document::create([
                        'participant_id'      => $participant->id,
                        'tenant_id'           => $tenantId,
                        'site_id'             => $participant->site_id,
                        'file_name'           => $doc['file_name'],
                        'file_path'           => $path,
                        'file_type'           => $doc['file_type'],
                        'file_size_bytes'     => $doc['file_size_bytes'],
                        'description'         => $doc['description'],
                        'document_category'   => $doc['document_category'],
                        'uploaded_by_user_id' => $primaryCareUser->id,
                    ]);
                    $docCount++;
                }
            }

            // ── Immunizations ────────────────────────────────────────────────
            if (! Immunization::where('participant_id', $participant->id)->exists()) {
                $immuns = [
                    ['vaccine_type' => 'influenza',    'vaccine_name' => 'Fluzone High-Dose Quadrivalent',  'cvx_code' => '185', 'manufacturer' => 'Sanofi Pasteur',  'vis_date' => '2023-08-25', 'months_ago' => rand(2, 10),  'dose' => 1],
                    ['vaccine_type' => 'pneumococcal_pcv20', 'vaccine_name' => 'Prevnar 20 (PCV20)',              'cvx_code' => '215', 'manufacturer' => 'Pfizer',           'vis_date' => '2022-11-04', 'months_ago' => rand(12, 36), 'dose' => 1],
                    ['vaccine_type' => 'covid_19',     'vaccine_name' => 'Moderna COVID-19 mRNA 2024-2025', 'cvx_code' => '919', 'manufacturer' => 'Moderna',          'vis_date' => '2024-06-27', 'months_ago' => rand(1, 6),   'dose' => 1],
                    ['vaccine_type' => 'shingles',       'vaccine_name' => 'Shingrix (RZV)',                  'cvx_code' => '187', 'manufacturer' => 'GlaxoSmithKline',  'vis_date' => '2022-01-14', 'months_ago' => rand(12, 48), 'dose' => 2],
                ];

                foreach ($immuns as $imm) {
                    Immunization::create([
                        'participant_id'          => $participant->id,
                        'tenant_id'               => $tenantId,
                        'vaccine_type'            => $imm['vaccine_type'],
                        'vaccine_name'            => $imm['vaccine_name'],
                        'cvx_code'                => $imm['cvx_code'],
                        'administered_date'       => now()->subMonths($imm['months_ago'])->format('Y-m-d'),
                        'administered_by_user_id' => $primaryCareUser->id,
                        'administered_at_location'=> 'Sunrise PACE Day Center',
                        'lot_number'              => strtoupper(substr($imm['vaccine_type'], 0, 3)) . rand(10000, 99999),
                        'manufacturer'            => $imm['manufacturer'],
                        'dose_number'             => $imm['dose'],
                        'refused'                 => false,
                        'vis_given'               => true,
                        'vis_publication_date'    => $imm['vis_date'],
                    ]);
                    $immunCount++;
                }
            }

            // ── Procedures ───────────────────────────────────────────────────
            if (! Procedure::where('participant_id', $participant->id)->exists()) {
                $procs = [
                    [
                        'procedure_name' => 'Electrocardiogram (12-Lead)',
                        'cpt_code'       => '93000',
                        'snomed_code'    => '29303009',
                        'performed_date' => now()->subMonths(rand(1, 6))->format('Y-m-d'),
                        'facility'       => 'Sunrise PACE Day Center',
                        'body_site'      => 'Chest / Limbs',
                        'outcome'        => 'Normal sinus rhythm. No acute ST changes identified.',
                        'notes'          => null,
                        'source'         => 'internal',
                    ],
                    [
                        'procedure_name' => 'Bone Density Scan (DEXA)',
                        'cpt_code'       => '77080',
                        'snomed_code'    => '312681000',
                        'performed_date' => now()->subMonths(rand(8, 24))->format('Y-m-d'),
                        'facility'       => 'Quest Radiology — Long Beach',
                        'body_site'      => 'Lumbar spine and hip',
                        'outcome'        => 'T-score -2.1. Mild osteopenia. Supplement with calcium/Vitamin D.',
                        'notes'          => 'Patient tolerated without difficulty.',
                        'source'         => 'external_report',
                    ],
                    [
                        'procedure_name' => 'Screening Colonoscopy',
                        'cpt_code'       => '45378',
                        'snomed_code'    => '444783004',
                        'performed_date' => now()->subYears(rand(2, 5))->format('Y-m-d'),
                        'facility'       => 'LA Endoscopy Center',
                        'body_site'      => 'Colon',
                        'outcome'        => 'Two 5mm sessile polyps removed. Follow-up in 5 years.',
                        'notes'          => 'Conscious sedation administered. Recovered without complications.',
                        'source'         => 'internal',
                    ],
                ];

                foreach ($procs as $proc) {
                    Procedure::create(array_merge($proc, [
                        'participant_id'       => $participant->id,
                        'tenant_id'            => $tenantId,
                        'performed_by_user_id' => $primaryCareUser->id,
                    ]));
                    $procCount++;
                }
            }

            // ── SDOH Screenings ───────────────────────────────────────────────
            if (! SocialDeterminant::where('participant_id', $participant->id)->exists()) {
                $sw = $socialWorkUser ?? $primaryCareUser;

                SocialDeterminant::create([
                    'participant_id'        => $participant->id,
                    'tenant_id'             => $tenantId,
                    'assessed_by_user_id'   => $sw->id,
                    'assessed_at'           => now()->subMonths(rand(6, 12)),
                    'housing_stability'     => 'stable',
                    'food_security'         => fake()->randomElement(['secure', 'at_risk']),
                    'transportation_access' => fake()->randomElement(['adequate', 'limited']),
                    'social_isolation_risk' => fake()->randomElement(['low', 'moderate']),
                    'caregiver_strain'      => fake()->randomElement(['none', 'mild']),
                    'financial_strain'      => fake()->randomElement(['none', 'mild']),
                    'safety_concerns'       => null,
                    'notes'                 => 'Initial SDOH screening at enrollment.',
                ]);

                SocialDeterminant::create([
                    'participant_id'        => $participant->id,
                    'tenant_id'             => $tenantId,
                    'assessed_by_user_id'   => $sw->id,
                    'assessed_at'           => now()->subMonths(rand(1, 4)),
                    'housing_stability'     => 'stable',
                    'food_security'         => fake()->randomElement(['secure', 'secure', 'at_risk']),
                    'transportation_access' => fake()->randomElement(['adequate', 'adequate', 'limited']),
                    'social_isolation_risk' => fake()->randomElement(['low', 'low', 'moderate']),
                    'caregiver_strain'      => fake()->randomElement(['none', 'none', 'mild']),
                    'financial_strain'      => fake()->randomElement(['none', 'mild']),
                    'safety_concerns'       => null,
                    'notes'                 => '6-month follow-up. Patient engaged with food pantry. Housing stable.',
                ]);

                $sdohCount += 2;
            }
        }

        $this->command->line("    - {$consentCount} consent records");
        $this->command->line("    - {$docCount} documents (with storage placeholders)");
        $this->command->line("    - {$immunCount} immunization records");
        $this->command->line("    - {$procCount} procedure records");
        $this->command->line("    - {$sdohCount} SDOH screenings");
    }
}
