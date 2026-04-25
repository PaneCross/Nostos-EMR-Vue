<?php

// ─── EncounterDataSubmissionController — Phase S4 ───────────────────────────
namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EdiBatch;
use App\Services\EncounterDataSubmission\AvailityEncounterDataGateway;
use App\Services\EncounterDataSubmission\DirectCmsEncounterDataGateway;
use App\Services\EncounterDataSubmission\EncounterDataGateway;
use App\Services\EncounterDataSubmission\NullEncounterDataGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EncounterDataSubmissionController extends Controller
{
    private function gateway(): EncounterDataGateway
    {
        return match (config('services.encounter_data.driver', 'null')) {
            'direct_cms' => new DirectCmsEncounterDataGateway(),
            'availity'   => new AvailityEncounterDataGateway(),
            default      => new NullEncounterDataGateway(),
        };
    }

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = ['finance', 'qa_compliance', 'it_admin', 'executive'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    public function index(Request $request)
    {
        $this->gate();
        $u = Auth::user();
        $batches = EdiBatch::where('tenant_id', $u->tenant_id)
            ->where('batch_type', 'edr')
            ->orderByDesc('created_at')->limit(50)->get();
        $driver = $this->gateway()->name();

        return \Inertia\Inertia::render('Billing/EncounterDataSubmission', [
            'driver'        => $driver,
            'driver_label'  => match ($driver) {
                'direct_cms' => 'Direct CMS EDS (paywall — Trading Partner Agreement required)',
                'availity'   => 'Availity routed to CMS EDS (paywall — clearinghouse contract)',
                default      => 'Null gateway (manual operator upload to CMS EDS portal)',
            },
            'is_real_vendor' => in_array($driver, ['direct_cms', 'availity'], true),
            'recent_batches' => $batches,
            'honest_label'   => 'Encounter Data Submission generates X12 837P files. The Null driver stages them for manual upload at https://eds.cms.gov; real-vendor drivers require contract + credentials.',
        ]);
    }

    public function submit(Request $request, EdiBatch $batch): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($batch->tenant_id !== $u->tenant_id, 403);
        abort_if($batch->batch_type !== 'edr', 422, 'Only EDR batches are submitted via this gateway.');

        $gateway = $this->gateway();
        $result = $gateway->submit($batch);

        $batch->update([
            'submitted_at'           => now(),
            'submission_method'      => $gateway->name() === 'null' ? 'direct' : 'clearinghouse',
            'clearinghouse_reference' => $result['reference'] ?? null,
            'status'                 => $result['status'] === 'staged' ? 'submitted' : ($result['status'] ?? 'submitted'),
        ]);

        AuditLog::record(
            action: 'encounter_data.submitted',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'edi_batch', resourceId: $batch->id,
            description: "Encounter data batch #{$batch->id} submitted via {$gateway->name()}",
        );

        return response()->json(['batch' => $batch->fresh(), 'gateway' => $gateway->name(), 'result' => $result]);
    }
}
