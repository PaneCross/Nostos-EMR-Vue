<?php

// ─── TeamHuddleController ────────────────────────────────────────────────────
// Phase G5. Morning-standup dashboard aggregating the day's high-priority
// items per department + printable PDF handout.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\ClinicalOrder;
use App\Models\DischargeEvent;
use App\Models\Incident;
use App\Models\LabResult;
use App\Models\Participant;
use App\Models\StaffTask;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class TeamHuddleController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
    }

    /** GET /huddle?department=X */
    public function show(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $dept = $request->query('department', $u->department);
        return response()->json($this->buildData($u->effectiveTenantId(), $dept));
    }

    /** GET /huddle/pdf?department=X */
    public function pdf(Request $request): Response
    {
        $this->gate();
        $u = Auth::user();
        $dept = $request->query('department', $u->department);
        $data = $this->buildData($u->effectiveTenantId(), $dept);
        $pdf = Pdf::loadView('pdfs.huddle', $data)->setPaper('letter', 'portrait');
        return $pdf->stream("huddle-{$dept}-" . now()->toDateString() . '.pdf');
    }

    private function buildData(int $tenantId, string $dept): array
    {
        $today = now()->startOfDay();
        $yesterday = now()->subDay();

        $overdueTasks = StaffTask::forTenant($tenantId)->overdue()
            ->where(function ($q) use ($dept) {
                $q->where('assigned_to_department', $dept);
            })->limit(50)->get();

        $criticalAlerts = Alert::where('tenant_id', $tenantId)
            ->where('severity', 'critical')
            ->whereNull('acknowledged_at')
            ->where(function ($q) use ($dept) {
                $q->whereJsonContains('target_departments', $dept)
                  ->orWhereJsonContains('target_departments', 'qa_compliance');
            })
            ->orderByDesc('created_at')->limit(50)->get();

        $newAdmissions = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->where('enrollment_date', '>=', $yesterday->toDateString())
            ->get(['id', 'mrn', 'first_name', 'last_name', 'enrollment_date']);

        $newDischarges = DischargeEvent::forTenant($tenantId)
            ->where('discharged_on', '>=', $yesterday->toDateString())
            ->with('participant:id,mrn,first_name,last_name')->get();

        $sentinels = Incident::forTenant($tenantId)->sentinels()
            ->where('sentinel_classified_at', '>=', $yesterday)
            ->with('participant:id,mrn,first_name,last_name')->get();

        $incomingOrders = ClinicalOrder::where('tenant_id', $tenantId)
            ->where('target_department', $dept)
            ->whereIn('status', ['pending', 'acknowledged'])
            ->with('participant:id,mrn,first_name,last_name')
            ->orderBy('priority')->orderBy('ordered_at')->limit(30)->get();

        $incomingLabs = LabResult::where('tenant_id', $tenantId)
            ->whereNull('reviewed_at')
            ->where('created_at', '>=', $yesterday)
            ->with('participant:id,mrn,first_name,last_name')
            ->limit(30)->get();

        return [
            'department'       => $dept,
            'date'             => $today->toDateString(),
            'overdue_tasks'    => $overdueTasks,
            'critical_alerts'  => $criticalAlerts,
            'new_admissions'   => $newAdmissions,
            'new_discharges'   => $newDischarges,
            'sentinel_events'  => $sentinels,
            'incoming_orders'  => $incomingOrders,
            'incoming_labs'    => $incomingLabs,
        ];
    }
}
