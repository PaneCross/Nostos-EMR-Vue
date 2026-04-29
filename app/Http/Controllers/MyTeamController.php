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

        // For each report, summarize their credential status
        $rows = $reports->map(function (User $u) use ($defSvc) {
            $creds = StaffCredential::forTenant($u->tenant_id)
                ->where('user_id', $u->id)
                ->whereNull('replaced_by_credential_id')
                ->get();

            $expiring = $creds->filter(fn ($c) => in_array($c->status(), ['due_60','due_30','due_14','due_today'], true))->count();
            $expired  = $creds->filter(fn ($c) => $c->status() === 'expired')->count();
            $invalid  = $creds->filter(fn ($c) => in_array($c->cms_status, ['suspended','revoked'], true))->count();
            $pending  = $creds->where('cms_status', 'pending')->count();
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
