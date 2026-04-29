<?php

// ─── WeeklyCredentialDigestJob ───────────────────────────────────────────────
// Monday 06:00 per-tenant rollup that aggregates credential coverage problems
// for IT Admin + QA Compliance. Acts as the no-silent-fail safety net to the
// per-user reminders : the dept gets one consolidated email summarizing
// everything that's expiring soon, currently overdue, or required-but-missing.
//
// Honors org pref 'credential_weekly_digest' (default ON, optional).
//
// Output: an in-app Alert + an email per dept member.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\StaffCredential;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Credentials\CredentialDefinitionService;
use App\Services\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class WeeklyCredentialDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function handle(
        AlertService $alertService,
        NotificationPreferenceService $prefService,
        CredentialDefinitionService $defService
    ): void {
        $tenants = Tenant::all();
        $totalDigests = 0;

        foreach ($tenants as $tenant) {
            // Honor org-level pref ; default ON
            if (! $prefService->shouldNotify($tenant->id, 'credential_weekly_digest')) {
                continue;
            }

            $digest = $this->buildDigest($tenant->id, $defService);

            // Skip if nothing notable
            if ($digest['expiring_30d'] === 0
                && $digest['overdue'] === 0
                && $digest['missing_required'] === 0
            ) {
                continue;
            }

            // Audit-4 A4 : dedup so a retry within the same week doesn't
            // double-create the digest alert. Key on (tenant, week-of-year).
            $weekKey = now()->isoFormat('GGGG-WW');
            $existing = \App\Models\Alert::where('tenant_id', $tenant->id)
                ->where('alert_type', 'credential_weekly_digest')
                ->where('is_active', true)
                ->whereJsonContains('metadata->digest_week', $weekKey)
                ->exists();
            if ($existing) {
                Log::info("[WeeklyCredentialDigestJob] dedup skip for tenant {$tenant->id} week {$weekKey}");
                continue;
            }

            $alertService->create([
                'tenant_id'          => $tenant->id,
                'source_module'      => 'it_admin',
                'alert_type'         => 'credential_weekly_digest',
                'severity'           => $digest['overdue'] > 0 || $digest['missing_required'] > 0 ? 'warning' : 'info',
                'title'              => "Weekly credential digest : {$digest['expiring_30d']} expiring, {$digest['overdue']} overdue, {$digest['missing_required']} missing",
                'message'            => $this->formatMessage($digest),
                'target_departments' => ['it_admin', 'qa_compliance'],
                'is_active'          => true,
                'metadata'           => [
                    'digest_date'        => now()->toDateString(),
                    'digest_week'        => $weekKey,
                    'expiring_30d'       => $digest['expiring_30d'],
                    'overdue'            => $digest['overdue'],
                    'missing_required'   => $digest['missing_required'],
                    'by_department'      => $digest['by_department'],
                ],
            ]);

            $totalDigests++;
        }

        Log::info('[WeeklyCredentialDigestJob] complete', [
            'tenants_scanned' => $tenants->count(),
            'digests_created' => $totalDigests,
        ]);
    }

    private function buildDigest(int $tenantId, CredentialDefinitionService $defService): array
    {
        // Expiring within 30 days (active, not yet expired). Filter to
        // tip-of-chain rows so superseded historical entries don't double-count.
        // Audit-4 A1 : skip credentials owned by deactivated users.
        $expiring30d = StaffCredential::forTenant($tenantId)
            ->whereNull('deleted_at')
            ->whereNull('replaced_by_credential_id')
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->expiringWithin(30)
            ->where('cms_status', 'active')
            ->count();

        // Currently overdue (expired but still on the books, status=active).
        $overdue = StaffCredential::forTenant($tenantId)
            ->whereNull('deleted_at')
            ->whereNull('replaced_by_credential_id')
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->expired()
            ->where('cms_status', 'active')
            ->count();

        // Required-but-missing : sum across users
        $missingRequired = 0;
        $byDept = [];
        $users = User::where('tenant_id', $tenantId)->where('is_active', true)->get();

        foreach ($users as $user) {
            $missing = $defService->missingForUser($user)->count();
            if ($missing === 0) continue;

            $missingRequired += $missing;
            $dept = $user->department ?? 'unknown';
            $byDept[$dept] = ($byDept[$dept] ?? 0) + $missing;
        }

        return [
            'expiring_30d'      => $expiring30d,
            'overdue'           => $overdue,
            'missing_required'  => $missingRequired,
            'by_department'     => $byDept,
        ];
    }

    private function formatMessage(array $d): string
    {
        $parts = [
            "Expiring in 30 days: {$d['expiring_30d']}",
            "Currently overdue: {$d['overdue']}",
            "Required-but-missing: {$d['missing_required']}",
        ];
        if (! empty($d['by_department'])) {
            $deptList = collect($d['by_department'])
                ->sortDesc()
                ->take(5)
                ->map(fn ($n, $dept) => "{$dept}({$n})")
                ->implode(', ');
            $parts[] = "Top affected departments: {$deptList}";
        }
        return implode(' · ', $parts) . '. Visit /executive/credentials-dashboard for full coverage matrix.';
    }
}
