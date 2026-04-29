<?php

// ─── CredentialComplianceService ─────────────────────────────────────────────
// Produces the dashboard payload for /executive/credentials-dashboard:
// rows = credential definitions, columns = departments, cells = coverage stats.
//
// Compliance is computed per (definition, department) pair :
//   users_required      : count of users in that dept the def applies to
//   users_compliant     : have an active (non-expired, status=active) credential linked
//   users_expiring_30d  : have one but expires within 30 days
//   users_expired       : have one but it's past expires_at
//   users_invalid       : have one but cms_status in (suspended, revoked, pending)
//   users_missing       : required but no credential row at all
//
// A user counts toward AT MOST ONE bucket per (def, dept) pair, with priority
// missing > invalid > expired > expiring_30d > compliant.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services\Credentials;

use App\Models\CredentialDefinition;
use App\Models\StaffCredential;
use App\Models\User;

class CredentialComplianceService
{
    public function __construct(private readonly CredentialDefinitionService $defService) {}

    public function matrixForTenant(int $tenantId): array
    {
        $users = User::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        $definitions = CredentialDefinition::forTenant($tenantId)
            ->active()
            ->with('targets')
            ->orderBy('sort_order')
            ->get();

        // Pre-fetch all (user_id, definition_id) credential links for efficiency.
        // Filter to tip-of-chain only : superseded rows (replaced_by set) are
        // audit-history and do not represent the user's current credential.
        $heldByUser = StaffCredential::where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereNotNull('credential_definition_id')
            ->whereNull('replaced_by_credential_id')
            ->get()
            ->groupBy('user_id');

        // Group users by department for column headers
        $userDepartments = $users->groupBy('department');
        $departments = $userDepartments->keys()->sort()->values()->all();

        $rows = [];

        foreach ($definitions as $def) {
            $row = [
                'definition_id'    => $def->id,
                'code'             => $def->code,
                'title'            => $def->title,
                'credential_type'  => $def->credential_type,
                'is_cms_mandatory' => (bool) $def->is_cms_mandatory,
                'cells'            => [],
                'totals'           => $this->emptyBucket(),
            ];

            foreach ($departments as $dept) {
                $cell = $this->emptyBucket();

                foreach ($userDepartments[$dept] ?? [] as $user) {
                    if (! $this->defService->userMatchesDefinition($user, $def)) continue;

                    $cell['users_required']++;
                    $row['totals']['users_required']++;

                    $bucket = $this->classifyUser($user, $def, $heldByUser[$user->id] ?? collect());
                    $cell[$bucket]++;
                    $row['totals'][$bucket]++;
                }

                $row['cells'][$dept] = $cell;
            }

            $rows[] = $row;
        }

        return [
            'departments' => $departments,
            'rows'        => $rows,
            'summary'     => $this->summarize($rows),
            // G2 : next-6-months expiration counts for the calendar widget
            'upcoming'    => $this->upcomingExpirations($tenantId),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * G2 : count of expiring credentials per upcoming month for the next 6
     * months. Used to drive the calendar bar chart on the dashboard.
     */
    private function upcomingExpirations(int $tenantId): array
    {
        $now = now()->startOfMonth();
        $months = [];
        for ($i = 0; $i < 6; $i++) {
            $m = $now->copy()->addMonths($i);
            $months[] = [
                'month_label' => $m->format('M Y'),
                'month_key'   => $m->format('Y-m'),
                'count'       => \App\Models\StaffCredential::where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->whereNull('replaced_by_credential_id')
                    ->whereYear('expires_at', $m->year)
                    ->whereMonth('expires_at', $m->month)
                    ->where('cms_status', 'active')
                    ->count(),
            ];
        }
        return $months;
    }

    /** Return one of: missing|invalid|expired|expiring_30d|compliant */
    private function classifyUser(User $user, CredentialDefinition $def, $userCreds): string
    {
        $matched = $userCreds->firstWhere('credential_definition_id', $def->id);

        if (! $matched) return 'users_missing';
        if (in_array($matched->cms_status, ['suspended', 'revoked', 'pending'], true)) {
            return 'users_invalid';
        }
        if ($matched->expires_at && $matched->expires_at->isPast()) return 'users_expired';
        if ($matched->expires_at
            && $matched->expires_at->diffInDays(now()->startOfDay(), false) >= -30) {
            return 'users_expiring_30d';
        }
        return 'users_compliant';
    }

    private function emptyBucket(): array
    {
        return [
            'users_required'     => 0,
            'users_compliant'    => 0,
            'users_expiring_30d' => 0,
            'users_expired'      => 0,
            'users_invalid'      => 0,
            'users_missing'      => 0,
        ];
    }

    private function summarize(array $rows): array
    {
        $totals = $this->emptyBucket();
        foreach ($rows as $r) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $r['totals'][$k];
            }
        }
        return $totals;
    }
}
