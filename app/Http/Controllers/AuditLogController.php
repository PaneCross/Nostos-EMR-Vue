<?php

// ─── AuditLogController ───────────────────────────────────────────────────────
// IT Admin panel: HIPAA-required audit log viewer and CSV exporter.
// Every read and write in the system is recorded to shared_audit_logs (append-only).
// Append-only by design : HIPAA non-repudiation. Never UPDATE these rows; new actions get new rows.
// IT Admin can search and filter this log, and export it as CSV for compliance audits.
//
// Routes (all require department='it_admin'):
//   GET /it-admin/audit         → audit()         (Inertia page)
//   GET /it-admin/audit/log     → auditLog()      (JSON paginated)
//   GET /it-admin/audit/export  → exportAuditCsv() (CSV download)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AuditLogController extends Controller
{
    /**
     * Render the audit log viewer page.
     * Passes the total log count so the page can show a record count before
     * the user applies any filters.
     */
    public function audit(Request $request): InertiaResponse
    {
        $this->requireItAdmin($request);

        return Inertia::render('ItAdmin/Audit', [
            'initialCount' => AuditLog::where('tenant_id', $request->user()->tenant_id)->count(),
        ]);
    }

    /**
     * Return a paginated audit log (JSON) for the log viewer, newest first.
     * Accepts ?action=, ?user_id=, ?resource_type=, ?date_from=, ?date_to= filters.
     */
    public function auditLog(Request $request): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        $query = AuditLog::where('tenant_id', $tenantId)
            ->with('user:id,first_name,last_name')
            ->orderByDesc('created_at');

        if ($request->filled('action')) {
            $query->where('action', 'like', '%' . $request->query('action') . '%');
        }
        // B8 : category quick-filter shortcut so users can pull "everything
        // credential-related" in one click without typing exact action keys.
        if ($request->filled('category')) {
            $patterns = match ($request->query('category')) {
                'credentials' => ['staff_credential.%', 'staff_training.%', 'credential_definition.%', 'job_title.%', '%role_assignment%'],
                default       => null,
            };
            if ($patterns) {
                $query->where(function ($q) use ($patterns) {
                    foreach ($patterns as $p) $q->orWhere('action', 'like', $p);
                });
            }
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }
        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->query('resource_type'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        return response()->json($query->paginate(100));
    }

    /**
     * Phase M2 : Single audit-log row detail for the UI modal.
     * GET /it-admin/audit/log/{log}
     */
    public function auditLogShow(Request $request, \App\Models\AuditLog $log): JsonResponse
    {
        $this->requireItAdmin($request);
        abort_if($log->tenant_id !== $request->user()->tenant_id, 403);
        $log->load('user:id,first_name,last_name,department');
        return response()->json(['log' => $log]);
    }

    /**
     * Export the audit log as a CSV file for compliance audits.
     *
     * Phase Y6 (Audit-13 L8): default window capped at the last 90 days +
     * 10,000 rows. Without a date filter, large tenants (1M+ rows) caused
     * a full table scan that blocked other IT-admin activity for ~30s.
     * Callers can override the window with ?from=YYYY-MM-DD&to=YYYY-MM-DD,
     * but the row cap still applies.
     */
    public function exportAuditCsv(Request $request): Response
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        $from = $request->filled('from')
            ? \Illuminate\Support\Carbon::parse($request->query('from'))->startOfDay()
            : now()->subDays(90)->startOfDay();
        $to = $request->filled('to')
            ? \Illuminate\Support\Carbon::parse($request->query('to'))->endOfDay()
            : now()->endOfDay();

        $rows = AuditLog::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->with('user:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->limit(10000)
            ->get();

        $filename = sprintf(
            'audit_log_%s_to_%s.csv',
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
        );
        $headers  = [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $csv = "ID,Action,Resource Type,Resource ID,User,IP Address,Created At\n";
        foreach ($rows as $row) {
            $userName = $row->user ? "{$row->user->first_name} {$row->user->last_name}" : 'System';
            $csv .= implode(',', [
                $row->id,
                $row->action,
                $row->resource_type ?? '',
                $row->resource_id   ?? '',
                $userName,
                $row->ip_address    ?? '',
                $row->created_at,
            ]) . "\n";
        }

        return response($csv, 200, $headers);
    }

    /** All routes in this controller require department='it_admin'. */
    private function requireItAdmin(Request $request): void
    {
        abort_if($request->user()->department !== 'it_admin', 403, 'IT Admin access required');
    }
}
