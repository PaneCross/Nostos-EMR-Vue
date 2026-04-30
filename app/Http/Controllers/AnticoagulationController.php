<?php

// ─── AnticoagulationController ───────────────────────────────────────────────
// Phase B5. Anticoagulation plan + INR endpoints.
//
// Routes:
//   GET  /participants/{participant}/anticoagulation           index()
//   POST /participants/{participant}/anticoagulation/plans     storePlan()
//   POST /anticoagulation-plans/{plan}/stop                    stopPlan()
//   POST /participants/{participant}/anticoagulation/inr       recordInr()
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AnticoagulationPlan;
use App\Models\AuditLog;
use App\Models\InrResult;
use App\Models\Participant;
use App\Services\AnticoagulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class AnticoagulationController extends Controller
{
    public function __construct(private AnticoagulationService $svc) {}

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        // Pharmacy + PCP + IT admin can manage anticoag workflow. (No nursing
        // department; nurses sit under primary_care / home_care.)
        $allow = ['primary_care', 'home_care', 'pharmacy', 'qa_compliance', 'it_admin'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    private function requireSameTenant($resource, $user): void
    {
        abort_if($resource->tenant_id !== $user->effectiveTenantId(), 403);
    }

    public function index(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $plan = $this->svc->activePlan($participant);
        $plans = AnticoagulationPlan::forTenant($u->effectiveTenantId())
            ->where('participant_id', $participant->id)
            ->with('prescribingProvider:id,first_name,last_name')
            ->orderByDesc('start_date')
            ->get();

        $trend = InrResult::where('participant_id', $participant->id)
            ->orderByDesc('drawn_at')
            ->limit(10)
            ->get();

        return response()->json([
            'active_plan' => $plan,
            'plans'       => $plans,
            'inr_trend'   => $trend,
        ]);
    }

    public function storePlan(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $validated = $request->validate([
            'agent'                        => 'required|in:' . implode(',', AnticoagulationPlan::AGENTS),
            'target_inr_low'               => 'nullable|numeric|min:1.0|max:4.9',
            'target_inr_high'              => 'nullable|numeric|min:1.1|max:5.0|gte:target_inr_low',
            'monitoring_interval_days'     => 'nullable|integer|min:1|max:180',
            'start_date'                   => 'required|date',
            'prescribing_provider_user_id' => 'nullable|integer|exists:shared_users,id',
            'notes'                        => 'nullable|string|max:4000',
        ]);

        // Warfarin typically requires INR targets; enforce at the app layer.
        if ($validated['agent'] === 'warfarin'
            && (empty($validated['target_inr_low']) || empty($validated['target_inr_high']))) {
            return response()->json([
                'error' => 'warfarin_requires_inr_target',
                'message' => 'Warfarin plans require target_inr_low + target_inr_high.',
            ], 422);
        }

        $plan = AnticoagulationPlan::create(array_merge($validated, [
            'tenant_id'      => $u->effectiveTenantId(),
            'participant_id' => $participant->id,
        ]));

        AuditLog::record(
            action: 'anticoag.plan_created',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'anticoagulation_plan',
            resourceId: $plan->id,
            description: "Anticoagulation plan ({$plan->agent}) created for participant #{$participant->id}.",
        );

        return response()->json(['plan' => $plan], 201);
    }

    public function stopPlan(Request $request, AnticoagulationPlan $plan): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($plan, $u);

        $validated = $request->validate([
            'stop_date'   => 'required|date|after_or_equal:' . $plan->start_date->toDateString(),
            'stop_reason' => 'required|string|min:3|max:200',
        ]);

        $plan->update($validated);

        AuditLog::record(
            action: 'anticoag.plan_stopped',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'anticoagulation_plan',
            resourceId: $plan->id,
            description: "Plan #{$plan->id} stopped: {$validated['stop_reason']}",
        );

        return response()->json(['plan' => $plan->fresh()]);
    }

    public function recordInr(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $validated = $request->validate([
            'value'                => 'required|numeric|min:0.5|max:15.0',
            'drawn_at'             => 'required|date',
            'dose_adjustment_text' => 'nullable|string|max:2000',
            'notes'                => 'nullable|string|max:4000',
        ]);

        $inr = $this->svc->recordInr(
            participant: $participant,
            value: (float) $validated['value'],
            drawnAt: Carbon::parse($validated['drawn_at']),
            user: $u,
            doseAdjustment: $validated['dose_adjustment_text'] ?? null,
            notes: $validated['notes'] ?? null,
        );

        return response()->json(['inr' => $inr->fresh(['plan'])], 201);
    }
}
