<?php

namespace App\Http\Controllers;

use App\Services\DiseaseRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class DiseaseRegistryController extends Controller
{
    public function __construct(private DiseaseRegistryService $svc) {}

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
    }

    /**
     * GET /registries/{registry}.
     * Phase O3: dual-serve JSON + Inertia via wantsJson() branch.
     * Registry page keys: diabetes | chf | copd.
     */
    public function show(Request $request, string $registry): JsonResponse|\Inertia\Response
    {
        $this->gate();
        $u = Auth::user();
        if (! $request->wantsJson()) {
            $componentMap = ['diabetes' => 'Diabetes', 'chf' => 'Chf', 'copd' => 'Copd'];
            $component = $componentMap[strtolower($registry)] ?? null;
            abort_if($component === null, 404);
            return \Inertia\Inertia::render("Registries/{$component}");
        }
        return response()->json($this->svc->cohort($u->tenant_id, $registry));
    }

    public function export(Request $request, string $registry): Response
    {
        $this->gate();
        $u = Auth::user();
        $csv = $this->svc->toCsv($u->tenant_id, $registry);
        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"registry-{$registry}.csv\"",
        ]);
    }
}
