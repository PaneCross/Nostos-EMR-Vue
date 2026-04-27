<?php

// ─── IntegrationStatusController ─────────────────────────────────────────────
// IT Admin panel: integration health monitoring and message log viewer.
// Shows the status of inbound HL7 ADT and lab result connectors, lets IT Admin
// browse the raw message log, and allows retrying failed entries.
//
// Routes (all require department='it_admin'):
//   GET  /it-admin/integrations               → integrations()
//   GET  /it-admin/integrations/log           → integrationLog()
//   POST /it-admin/integrations/{log}/retry   → retryIntegration()
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Jobs\ProcessHl7AdtJob;
use App\Jobs\ProcessLabResultJob;
use App\Models\AuditLog;
use App\Models\EligibilityCheck;
use App\Models\IntegrationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class IntegrationStatusController extends Controller
{
    /**
     * Render the integrations monitoring page.
     * Shows a per-connector status card (last received, last status, failure count)
     * plus the 20 most recent log entries for the initial table view.
     */
    public function integrations(Request $request): InertiaResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        $summary = [];
        foreach (IntegrationLog::CONNECTOR_TYPES as $type) {
            $last = IntegrationLog::forTenant($tenantId)
                ->forConnector($type)
                ->latest('created_at')
                ->first();

            $summary[$type] = [
                'last_received' => $last?->created_at,
                'last_status'   => $last?->status,
                'failed_count'  => IntegrationLog::forTenant($tenantId)->forConnector($type)->failed()->count(),
            ];
        }

        $recentLog = IntegrationLog::forTenant($tenantId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'connector_type', 'direction', 'status', 'error_message', 'retry_count', 'created_at', 'processed_at']);

        // Phase Q4 : eligibility driver visibility for IT admin
        $eligibilityDriver = config('services.eligibility.driver', 'null');
        $eligibility = [
            'driver'           => $eligibilityDriver,
            'driver_label'     => match ($eligibilityDriver) {
                'availity'           => 'Availity (X12 270/271 : paywall item 16)',
                'change_healthcare'  => 'Change Healthcare (X12 270/271 : paywall item 16)',
                default              => 'Null gateway (no real eligibility verification)',
            },
            'is_real_vendor'   => in_array($eligibilityDriver, ['availity', 'change_healthcare'], true),
            'recent_checks_30d' => EligibilityCheck::forTenant($tenantId)
                ->where('requested_at', '>=', now()->subDays(30))->count(),
            'config_note'      => 'Set ELIGIBILITY_DRIVER=availity|change_healthcare in .env once a vendor contract is signed. Until then, the Null gateway returns honest-labeled "unknown" responses.',
        ];

        return Inertia::render('ItAdmin/Integrations', [
            'summary'        => $summary,
            'recentLog'      => $recentLog,
            'connectorTypes' => IntegrationLog::CONNECTOR_TYPES,
            'eligibility'    => $eligibility,
        ]);
    }

    /**
     * Return a paginated integration log (JSON), newest first.
     * Accepts ?connector_type= and ?status= filters.
     */
    public function integrationLog(Request $request): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        $query = IntegrationLog::forTenant($tenantId)->orderByDesc('created_at');

        if ($request->filled('connector_type')) {
            $query->forConnector($request->query('connector_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json($query->paginate(50));
    }

    /**
     * Retry a failed integration log entry by re-dispatching its job.
     * Only entries with status='failed' can be retried.
     */
    public function retryIntegration(Request $request, IntegrationLog $log): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        abort_if($log->tenant_id !== $tenantId, 403, 'Access denied');
        abort_if($log->status !== 'failed', 422, 'Only failed entries can be retried');

        $log->markRetried();

        match ($log->connector_type) {
            'hl7_adt'     => ProcessHl7AdtJob::dispatch($log->id, $log->raw_payload, $tenantId)->onQueue('integrations'),
            'lab_results' => ProcessLabResultJob::dispatch($log->id, $log->raw_payload, $tenantId)->onQueue('integrations'),
            default       => null,
        };

        AuditLog::record(
            action:       'it_admin.integration.retry',
            resourceType: 'IntegrationLog',
            resourceId:   $log->id,
            tenantId:     $tenantId,
            userId:       $request->user()->id,
        );

        return response()->json(['retried' => true, 'retry_count' => $log->retry_count]);
    }

    /** All routes in this controller require department='it_admin'. */
    private function requireItAdmin(Request $request): void
    {
        abort_if($request->user()->department !== 'it_admin', 403, 'IT Admin access required');
    }
}
