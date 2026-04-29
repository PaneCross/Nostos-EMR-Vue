<?php

// ─── MyTeamController ────────────────────────────────────────────────────────
// D7 : supervisors see consolidated credential status of their direct reports.
// Works for any user who has at least one row in shared_users with
// supervisor_user_id pointing to them. Read-only view.
//
// Eligibility : authenticated user with directReports().exists()
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\StaffCredential;
use App\Models\User;
use App\Services\Credentials\CredentialDefinitionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class MyTeamController extends Controller
{
    public function index(Request $request, CredentialDefinitionService $defSvc): InertiaResponse
    {
        $supervisor = $request->user();
        abort_unless($supervisor, 401);

        $reports = User::where('supervisor_user_id', $supervisor->id)
            ->where('is_active', true)
            ->orderBy('last_name')
            ->get(['id', 'tenant_id', 'first_name', 'last_name', 'email', 'department', 'job_title']);

        if ($reports->isEmpty()) {
            return Inertia::render('User/MyTeam', ['reports' => []]);
        }

        // Audit-4 G4 : log the supervisor's view of their team's credential
        // status. Some employee-health credentials (TB clearance, immunizations)
        // count as worker PHI in strict shops ; this gives ops a paper trail.
        \App\Models\AuditLog::record(
            action: 'my_team.viewed',
            resourceType: 'User',
            resourceId: $supervisor->id,
            tenantId: $supervisor->tenant_id,
            userId: $supervisor->id,
            newValues: ['report_count' => $reports->count(), 'report_ids' => $reports->pluck('id')->all()],
        );

        // Audit-3 fix : vectorize the per-report queries so we don't N+1.
        // One query for all credentials across all reports, grouped by user_id.
        $reportIds = $reports->pluck('id')->all();
        $credsByUser = StaffCredential::where('tenant_id', $supervisor->tenant_id)
            ->whereIn('user_id', $reportIds)
            ->whereNull('replaced_by_credential_id')
            ->get()
            ->groupBy('user_id');

        $rows = $reports->map(function (User $u) use ($defSvc, $credsByUser) {
            $creds = $credsByUser->get($u->id, collect());

            $expiring = $creds->filter(fn ($c) => in_array($c->status(), ['due_60','due_30','due_14','due_today'], true))->count();
            $expired  = $creds->filter(fn ($c) => $c->status() === 'expired')->count();
            $invalid  = $creds->filter(fn ($c) => in_array($c->cms_status, ['suspended','revoked'], true))->count();
            $pending  = $creds->where('cms_status', 'pending')->count();
            // missingForUser still hits the catalog tables ; that's bounded by
            // catalog size (small), not by report count, so fine to leave.
            $missing  = $defSvc->missingForUser($u)->count();

            $worst = match (true) {
                $expired > 0 || $invalid > 0 || $missing > 0 => 'red',
                $expiring > 0 || $pending > 0                => 'amber',
                default                                       => 'green',
            };

            return [
                'id' => $u->id,
                'name' => "{$u->first_name} {$u->last_name}",
                'email' => $u->email,
                'department' => $u->department,
                'job_title' => $u->job_title,
                'on_file' => $creds->count(),
                'expiring_count' => $expiring,
                'expired_count'  => $expired,
                'invalid_count'  => $invalid,
                'pending_count'  => $pending,
                'missing_count'  => $missing,
                'severity' => $worst,
            ];
        });

        return Inertia::render('User/MyTeam', [
            'reports' => $rows,
        ]);
    }
}
