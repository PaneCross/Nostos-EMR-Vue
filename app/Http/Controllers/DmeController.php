<?php

// ─── DmeController : Phase S3 ───────────────────────────────────────────────
namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DmeIssuance;
use App\Models\DmeItem;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DmeController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = ['therapies', 'home_care', 'finance', 'qa_compliance', 'it_admin', 'idt'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    public function index(Request $request)
    {
        $this->gate();
        $u = Auth::user();
        $items = DmeItem::forTenant($u->effectiveTenantId())
            ->orderBy('item_type')->orderBy('id')
            ->get();
        $openIssuances = DmeIssuance::where('tenant_id', $u->effectiveTenantId())
            ->whereNull('returned_at')
            ->with(['item:id,item_type,manufacturer,model', 'participant:id,mrn,first_name,last_name'])
            ->orderBy('issued_at')
            ->get();

        return \Inertia\Inertia::render('Network/Dme', [
            'items'           => $items,
            'open_issuances'  => $openIssuances,
            'item_statuses'   => DmeItem::STATUSES,
            'return_conditions' => DmeIssuance::RETURN_CONDITIONS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $validated = $request->validate([
            'item_type'     => 'required|string|max:50',
            'manufacturer'  => 'nullable|string|max:100',
            'model'         => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100',
            'hcpcs_code'    => 'nullable|string|max:10',
            'purchase_date' => 'nullable|date',
            'purchase_cost' => 'nullable|numeric|min:0',
            'notes'         => 'nullable|string|max:2000',
        ]);
        $item = DmeItem::create(array_merge($validated, [
            'tenant_id' => $u->effectiveTenantId(),
            'status'    => 'available',
        ]));
        AuditLog::record(action: 'dme.item_added', tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'dme_item', resourceId: $item->id,
            description: "DME item registered: {$item->item_type}");
        return response()->json(['item' => $item], 201);
    }

    public function issue(Request $request, DmeItem $dme): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($dme->tenant_id !== $u->effectiveTenantId(), 403);
        abort_if($dme->status !== 'available', 422, "Item not available (status={$dme->status})");

        $validated = $request->validate([
            'participant_id'      => 'required|integer|exists:emr_participants,id',
            'issued_at'           => 'required|date',
            'expected_return_at'  => 'nullable|date|after_or_equal:issued_at',
            'issue_notes'         => 'nullable|string|max:2000',
        ]);
        // Cross-tenant participant check
        $p = Participant::find($validated['participant_id']);
        abort_if(! $p || $p->tenant_id !== $u->effectiveTenantId(), 403);

        return DB::transaction(function () use ($dme, $u, $validated) {
            $issuance = DmeIssuance::create([
                'tenant_id'          => $u->effectiveTenantId(),
                'dme_item_id'        => $dme->id,
                'participant_id'     => $validated['participant_id'],
                'issued_at'          => $validated['issued_at'],
                'issued_by_user_id'  => $u->id,
                'expected_return_at' => $validated['expected_return_at'] ?? null,
                'issue_notes'        => $validated['issue_notes'] ?? null,
            ]);
            $dme->update(['status' => 'issued']);

            AuditLog::record(action: 'dme.issued', tenantId: $u->tenant_id, userId: $u->id,
                resourceType: 'dme_issuance', resourceId: $issuance->id,
                description: "DME {$dme->item_type} issued to participant #{$issuance->participant_id}");

            return response()->json(['issuance' => $issuance, 'item' => $dme->fresh()], 201);
        });
    }

    public function return_(Request $request, DmeIssuance $issuance): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($issuance->tenant_id !== $u->effectiveTenantId(), 403);
        abort_if($issuance->returned_at !== null, 422, 'Issuance already closed');

        $validated = $request->validate([
            'returned_at'      => 'required|date|after_or_equal:' . $issuance->issued_at->toDateString(),
            'return_condition' => 'required|in:' . implode(',', DmeIssuance::RETURN_CONDITIONS),
            'return_notes'     => 'nullable|string|max:2000',
        ]);

        return DB::transaction(function () use ($issuance, $u, $validated) {
            $issuance->update(array_merge($validated, [
                'returned_to_user_id' => $u->id,
            ]));
            $newStatus = $validated['return_condition'] === 'lost' ? 'lost' : 'available';
            $issuance->item->update(['status' => $newStatus]);

            AuditLog::record(action: 'dme.returned', tenantId: $u->tenant_id, userId: $u->id,
                resourceType: 'dme_issuance', resourceId: $issuance->id,
                description: "DME returned ({$validated['return_condition']})");

            return response()->json(['issuance' => $issuance->fresh(), 'item' => $issuance->item->fresh()]);
        });
    }
}
