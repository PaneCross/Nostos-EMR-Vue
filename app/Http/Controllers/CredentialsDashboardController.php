<?php

// ─── CredentialsDashboardController ──────────────────────────────────────────
// Executive-only view of credential coverage org-wide. Renders a matrix of
// (definitions × departments) showing compliance bucket counts per cell,
// plus drill-down to the user list behind any cell.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\CredentialDefinition;
use App\Models\StaffCredential;
use App\Models\User;
use App\Services\Credentials\CredentialComplianceService;
use App\Services\Credentials\CredentialDefinitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CredentialsDashboardController extends Controller
{
    private function gate(Request $request): void
    {
        $u = $request->user();
        abort_unless($u, 401);
        abort_unless(
            $u->isSuperAdmin()
                || in_array($u->department, ['executive', 'qa_compliance', 'it_admin'], true),
            403,
            'Only Executive, QA Compliance, IT Admin (or Super Admin) may view the credentials dashboard.'
        );
    }

    public function index(
        Request $request,
        CredentialComplianceService $svc
    ): InertiaResponse {
        $this->gate($request);

        $matrix = $svc->matrixForTenant($request->user()->tenant_id);

        return Inertia::render('Executive/CredentialsDashboard', [
            'matrix' => $matrix,
        ]);
    }

    /** Drill-down endpoint : list users in one (definition × department × bucket) cell. */
    public function drilldown(
        Request $request,
        int $definitionId,
        string $department,
        string $bucket,
        CredentialDefinitionService $defSvc
    ): JsonResponse {
        $this->gate($request);

        $tenantId = $request->user()->tenant_id;
        $def = CredentialDefinition::forTenant($tenantId)->findOrFail($definitionId);

        $candidates = User::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('department', $department)
            ->get();

        // Build per-user credential row map
        $heldByUser = StaffCredential::where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('credential_definition_id', $def->id)
            ->get()
            ->keyBy('user_id');

        $users = $candidates
            ->filter(fn (User $u) => $defSvc->userMatchesDefinition($u, $def))
            ->map(function (User $u) use ($heldByUser, $bucket) {
                $cred = $heldByUser[$u->id] ?? null;
                $b = $this->classify($cred);

                // 'all' or 'users_required' returns every user in the cell.
                // Specific buckets filter to that classification only.
                if ($bucket !== 'all' && $bucket !== 'users_required' && $b !== $bucket) {
                    return null;
                }

                return [
                    'user_id'      => $u->id,
                    'name'         => "{$u->first_name} {$u->last_name}",
                    'job_title'    => $u->job_title,
                    'credential_id'=> $cred?->id,
                    'expires_at'   => $cred?->expires_at?->toDateString(),
                    'cms_status'   => $cred?->cms_status,
                    'days_remaining' => $cred?->daysUntilExpiration(),
                    'bucket'       => $b,
                ];
            })
            ->filter()
            ->sortBy(fn ($u) => match ($u['bucket']) {
                // missing / invalid first, compliant last
                'users_missing'      => 0,
                'users_invalid'      => 1,
                'users_expired'      => 2,
                'users_expiring_30d' => 3,
                'users_compliant'    => 4,
                default              => 5,
            })
            ->values();

        return response()->json([
            'definition' => ['id' => $def->id, 'title' => $def->title],
            'department' => $department,
            'bucket'     => $bucket,
            'users'      => $users,
        ]);
    }

    private function classify(?StaffCredential $cred): string
    {
        if (! $cred) return 'users_missing';
        if (in_array($cred->cms_status, ['suspended', 'revoked', 'pending'], true)) {
            return 'users_invalid';
        }
        if ($cred->expires_at && $cred->expires_at->isPast()) return 'users_expired';
        if ($cred->expires_at && $cred->expires_at->diffInDays(now()->startOfDay(), false) >= -30) {
            return 'users_expiring_30d';
        }
        return 'users_compliant';
    }
}
