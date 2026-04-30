<?php

namespace App\Http\Controllers;

use App\Models\SavedDashboard;
use App\Services\ReportBuilderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdvancedBiController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
    }

    public function schema(ReportBuilderService $svc): JsonResponse
    {
        $this->gate();
        return response()->json($svc->schema());
    }

    public function runReport(Request $request, ReportBuilderService $svc): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $validated = $request->validate([
            'entity'     => 'required|string',
            'dimension'  => 'required|string',
            'measure'    => 'nullable|string',
            'joins'      => 'nullable|array',
            'joins.*'    => 'string',
        ]);
        return response()->json($svc->run($u->effectiveTenantId(), $validated));
    }

    public function dashboardsIndex(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $rows = SavedDashboard::forTenant($u->effectiveTenantId())
            ->where(function ($q) use ($u) {
                $q->where('owner_user_id', $u->id)->orWhere('is_shared', true);
            })
            ->orderByDesc('updated_at')->get();
        return response()->json(['dashboards' => $rows]);
    }

    public function dashboardsStore(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $validated = $request->validate([
            'title'       => 'required|string|max:200',
            'description' => 'nullable|string|max:4000',
            // Phase O7 : `present` allows [] (user creates an empty dashboard
            // and adds widgets via the builder UI afterwards).
            'widgets'     => 'present|array',
            'is_shared'   => 'nullable|boolean',
        ]);
        $d = SavedDashboard::create(array_merge($validated, [
            'tenant_id'     => $u->effectiveTenantId(),
            'owner_user_id' => $u->id,
            'is_shared'     => $validated['is_shared'] ?? false,
        ]));
        return response()->json(['dashboard' => $d], 201);
    }

    public function dashboardsShow(Request $request, SavedDashboard $dashboard): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($dashboard->tenant_id !== $u->effectiveTenantId(), 403);
        abort_if(! $dashboard->is_shared && $dashboard->owner_user_id !== $u->id, 403);
        return response()->json(['dashboard' => $dashboard]);
    }
}
