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

    public function show(Request $request, string $registry): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
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
