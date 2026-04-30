<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DietaryOrder;
use App\Models\Participant;
use App\Models\Problem;
use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DietaryOrderController extends Controller
{
    public function __construct(private AlertService $alerts) {}

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        $allow = ['primary_care', 'dietary', 'pharmacy', 'home_care', 'qa_compliance', 'it_admin'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    private function requireSameTenant($r, $u): void { abort_if($r->tenant_id !== $u->effectiveTenantId(), 403); }

    public function index(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);
        return response()->json(['orders' => DietaryOrder::forTenant($u->effectiveTenantId())
            ->where('participant_id', $participant->id)
            ->orderByDesc('effective_date')->get()]);
    }

    public function store(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $validated = $request->validate([
            'diet_type'                    => 'required|in:' . implode(',', DietaryOrder::DIET_TYPES),
            'calorie_target'               => 'nullable|integer|min:0|max:10000',
            'fluid_restriction_ml_per_day' => 'nullable|integer|min:0|max:10000',
            'texture_modification'         => 'nullable|string|max:40',
            'allergen_exclusions'          => 'nullable|string|max:2000',
            'effective_date'               => 'required|date',
            'rationale'                    => 'nullable|string|max:2000',
            'notes'                        => 'nullable|string|max:4000',
        ]);

        // Discontinue any prior active order on same participant (one active at a time).
        DietaryOrder::where('participant_id', $participant->id)
            ->whereNull('discontinued_date')
            ->update(['discontinued_date' => $validated['effective_date']]);

        $order = DietaryOrder::create(array_merge($validated, [
            'tenant_id'         => $u->effectiveTenantId(),
            'participant_id'    => $participant->id,
            'ordered_by_user_id'=> $u->id,
        ]));

        AuditLog::record(
            action: 'dietary.order_created',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'dietary_order',
            resourceId: $order->id,
            description: "Dietary order: {$order->diet_type}",
        );

        // Inconsistency check: diabetic diagnosis + regular diet.
        $hasDm = Problem::where('participant_id', $participant->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where('icd10_code', 'like', 'E10%')
                  ->orWhere('icd10_code', 'like', 'E11%')
                  ->orWhere('icd10_description', 'ilike', '%diabetes%');
            })->exists();

        if ($hasDm && $order->diet_type === 'regular') {
            $this->alerts->create([
                'tenant_id'          => $u->effectiveTenantId(),
                'participant_id'     => $participant->id,
                'source_module'      => 'dietary',
                'alert_type'         => 'dietary_order_inconsistent',
                'severity'           => 'warning',
                'title'              => 'Dietary order inconsistent with diagnosis',
                'message'            => "Participant has active diabetes diagnosis but dietary order is 'regular'. Consider diabetic diet.",
                'target_departments' => ['dietary', 'primary_care'],
                'metadata'           => ['dietary_order_id' => $order->id],
            ]);
        }

        return response()->json(['order' => $order], 201);
    }

    public function discontinue(Request $request, DietaryOrder $order): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($order, $u);

        if ($order->discontinued_date !== null) {
            return response()->json(['error' => 'already_discontinued'], 409);
        }
        $validated = $request->validate([
            'discontinued_date' => 'required|date|after_or_equal:' . $order->effective_date->toDateString(),
        ]);
        $order->update(['discontinued_date' => $validated['discontinued_date']]);

        AuditLog::record(
            action: 'dietary.order_discontinued',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'dietary_order',
            resourceId: $order->id,
            description: "Dietary order discontinued on {$validated['discontinued_date']}.",
        );
        return response()->json(['order' => $order->fresh()]);
    }

    /** Dietary department roster : active orders grouped by diet_type. */
    public function roster(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();

        $orders = DietaryOrder::forTenant($u->effectiveTenantId())->active()
            ->with('participant:id,mrn,first_name,last_name,site_id')
            ->get();

        return response()->json(['groups' => $orders->groupBy('diet_type')]);
    }
}
