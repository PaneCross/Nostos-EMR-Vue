<?php

// ─── AuditLogController ───────────────────────────────────────────────────────
// IT Admin panel: HIPAA-required audit log viewer and CSV exporter.
// Every read and write in the system is recorded to shared_audit_logs (append-only).
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
     * Phase M2 — Single audit-log row detail for the UI modal.
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
     * Export the full audit log as a CSV file for compliance audits.
     * Capped at 10,000 rows. Filename includes today's date.
     */
    public function exportAuditCsv(Request $request): Response
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        $rows = AuditLog::where('tenant_id', $tenantId)
            ->with('user:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->limit(10000)
            ->get();

        $filename = 'audit_log_' . now()->format('Y-m-d') . '.csv';
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
