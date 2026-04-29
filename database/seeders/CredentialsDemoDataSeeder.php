<?php

// ─── CredentialsDemoDataSeeder ───────────────────────────────────────────────
// Seeds realistic StaffCredential rows for every active demo user, linked to
// the catalog (definition_id set) so the dashboard, missing-banners, and
// renewal flows have meaningful data to demo against.
//
// Strategy : for each (user, applicable definition):
//   - 70% : current (expires_at 30-700 days out, status=active)
//   - 12% : expiring within 30 days
//   - 8%  : expired (1-90 days past expires_at)
//   - 5%  : pending (renewal uploaded, awaiting verification)
//   - 5%  : missing entirely (no credential row created)
//
// Distribution is deterministic per (user_id, definition_id) so re-running the
// seeder doesn't shuffle the dashboard — same demo experience each time.
//
// Wipes all existing demo StaffCredential rows for the tenant first to keep
// idempotency. Adds DOT med card / MVR fields for driver records.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\CredentialDefinition;
use App\Models\StaffCredential;
use App\Models\StaffTrainingRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Credentials\CredentialDefinitionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class CredentialsDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // B9 : refuse to run in production unless explicitly forced. This
        // seeder is destructive (wipes all StaffCredential rows for every
        // tenant) and was meant for demo data only.
        if (app()->environment('production')) {
            $force = (bool) env('CREDENTIALS_DEMO_FORCE_PRODUCTION', false);
            if (! $force) {
                $this->command?->error('CredentialsDemoDataSeeder refuses to run in production. Set CREDENTIALS_DEMO_FORCE_PRODUCTION=true to override (DANGEROUS : wipes all staff credential rows).');
                return;
            }
            $this->command?->warn('Running CredentialsDemoDataSeeder in production with force flag. This will wipe all existing staff credentials.');
        }

        /** @var CredentialDefinitionService $svc */
        $svc = app(CredentialDefinitionService::class);

        Tenant::all()->each(function (Tenant $tenant) use ($svc) {
            // Wipe existing tenant credentials (clean re-seed)
            StaffCredential::where('tenant_id', $tenant->id)->forceDelete();

            $users = User::where('tenant_id', $tenant->id)->where('is_active', true)->get();

            foreach ($users as $user) {
                $applicable = $svc->activeForUser($user);
                foreach ($applicable as $def) {
                    // Deterministic seed : same user+def always lands in same bucket
                    $bucket = $this->bucketFor($user->id, $def->id);

                    if ($bucket === 'missing') continue; // no row created

                    $cred = $this->createCredential($user, $def, $bucket);
                    $this->maybeAddDriverFields($cred, $def);
                }
            }

            // B1 demo : link some training records to CEU-tracking credentials
            // so the "X / Y CEU hrs" progress display is non-empty across the
            // demo. We pick credentials with ceu_hours_required > 0 and the
            // user's existing training records, and assign training_record.
            // credential_id to roughly half of them per user.
            $this->linkDemoTrainingToCredentials($tenant->id);

            // B6 demo : create one site-only "extra" definition + one
            // site-disable override so the per-site overrides UI has data.
            $this->seedSiteOverrideDemo($tenant->id);
        });
    }

    private function seedSiteOverrideDemo(int $tenantId): void
    {
        $sites = \App\Models\Site::where('tenant_id', $tenantId)->orderBy('id')->get();
        if ($sites->count() < 1) return;
        $firstSite = $sites->first();
        $secondSite = $sites->skip(1)->first();

        // Site-only extra : "Bilingual proficiency cert" specific to one site.
        \App\Models\CredentialDefinition::updateOrCreate(
            ['tenant_id' => $tenantId, 'site_id' => $firstSite->id, 'code' => 'bilingual_proficiency_cert'],
            [
                'title'                 => 'Bilingual Proficiency Certification (Site-only)',
                'credential_type'       => 'certification',
                'description'           => 'Site-specific cert for staff providing language services. Only required at this one site.',
                'requires_psv'          => false,
                'is_cms_mandatory'      => false,
                'default_doc_required'  => true,
                'reminder_cadence_days' => [30, 0],
                'ceu_hours_required'    => 0,
                'is_active'             => true,
                'sort_order'            => 200,
            ]
        );

        // Site-disable override : disable the (non-mandatory) ACLS cert at the
        // second site (e.g. site B doesn't run ACLS-level emergencies).
        if ($secondSite) {
            $aclsDef = \App\Models\CredentialDefinition::where('tenant_id', $tenantId)
                ->where('code', 'acls_certification')->first();
            if ($aclsDef) {
                \App\Models\CredentialDefinitionSiteOverride::updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'site_id'   => $secondSite->id,
                        'credential_definition_id' => $aclsDef->id,
                    ],
                    [
                        'action' => 'disabled',
                    ]
                );
            }
        }
    }

    private function linkDemoTrainingToCredentials(int $tenantId): void
    {
        $ceuCreds = StaffCredential::where('tenant_id', $tenantId)
            ->whereNull('replaced_by_credential_id')
            ->whereHas('definition', fn ($q) => $q->where('ceu_hours_required', '>', 0))
            ->get(['id', 'user_id', 'credential_definition_id']);

        // Group by user so we link multiple training records under one cred per user
        $byUser = $ceuCreds->groupBy('user_id');

        foreach ($byUser as $userId => $creds) {
            // Pick the first CEU-tracking cred for this user
            $cred = $creds->first();
            $training = StaffTrainingRecord::where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->whereNull('credential_id')
                ->limit(2)
                ->get();

            foreach ($training as $t) {
                $t->update(['credential_id' => $cred->id]);
            }
        }
    }

    /** Stable bucket assignment : seed-then-mod gives reproducible spread. */
    private function bucketFor(int $userId, int $defId): string
    {
        $hash = ($userId * 31 + $defId * 7) % 100;
        if ($hash < 70) return 'current';
        if ($hash < 82) return 'expiring_30d';
        if ($hash < 90) return 'expired';
        if ($hash < 95) return 'pending';
        return 'missing';
    }

    private function createCredential(User $user, CredentialDefinition $def, string $bucket): StaffCredential
    {
        // Pick reasonable cycle length per type
        $cycleDays = match ($def->credential_type) {
            'license'        => 730,  // 2y typical
            'driver_record'  => $def->code === 'dot_medical_card' ? 730 : 365,
            'certification'  => 730,
            'training'       => 365,
            'tb_clearance'   => 365,
            'background_check' => 730,
            default          => 365,
        };

        // Deterministic offset within bucket
        $offset = ($user->id * 13 + $def->id * 5) % 100;

        $expiresAt = match ($bucket) {
            'current'      => now()->addDays(60 + $offset * ($cycleDays / 100)),
            'expiring_30d' => now()->addDays(($offset % 28) + 1),
            'expired'      => now()->subDays(($offset % 80) + 5),
            'pending'      => now()->addDays(60 + $offset),
            default        => now()->addYear(),
        };

        $issuedAt = $expiresAt->copy()->subDays($cycleDays);

        $licenseState = null;
        $licenseNumber = null;
        if (str_contains($def->code, 'license') || $def->credential_type === 'license' || $def->code === 'cdl') {
            $licenseState = 'CA';
            $licenseNumber = strtoupper(substr($def->code, 0, 3)) . str_pad((string) ($user->id * 1234 % 99999), 5, '0', STR_PAD_LEFT);
        }

        $verificationSource = $bucket === 'pending'
            ? 'self_attestation'
            : ($def->requires_psv ? 'state_board' : 'uploaded_doc');

        return StaffCredential::create([
            'tenant_id'                => $user->tenant_id,
            'user_id'                  => $user->id,
            'credential_definition_id' => $def->id,
            'credential_type'          => $def->credential_type,
            'title'                    => $def->title,
            'license_state'            => $licenseState,
            'license_number'           => $licenseNumber,
            'issued_at'                => $issuedAt->toDateString(),
            'expires_at'               => $expiresAt->toDateString(),
            'verified_at'              => $bucket === 'pending' ? null : now()->subDays(rand(1, 90)),
            'verification_source'      => $verificationSource,
            'cms_status'               => $bucket === 'pending' ? 'pending' : 'active',
            'notes'                    => "Demo seed : bucket={$bucket}",
        ]);
    }

    /** For driver-record definitions, populate the FMCSA-specific fields. */
    private function maybeAddDriverFields(StaffCredential $cred, CredentialDefinition $def): void
    {
        if ($def->credential_type !== 'driver_record') return;

        $patch = [];
        if ($def->code === 'cdl') {
            $patch['vehicle_class_endorsements'] = 'Class B + P (passenger)';
        }
        if ($def->code === 'dot_medical_card') {
            $patch['dot_medical_card_expires_at'] = $cred->expires_at;
        }
        if ($def->code === 'mvr_check') {
            $patch['mvr_check_date'] = $cred->issued_at;
        }
        if (! empty($patch)) {
            $cred->update($patch);
        }
    }
}
