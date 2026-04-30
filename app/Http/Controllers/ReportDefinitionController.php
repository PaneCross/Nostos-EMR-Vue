<?php

// ─── ReportDefinitionController ──────────────────────────────────────────────
// Phase 15.3 : CRUD + run + CSV export for custom report definitions.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ReportDefinition;
use App\Services\ReportRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReportDefinitionController extends Controller
{
    public function __construct(private ReportRunService $runner) {}

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        abort_unless(
            $u->isSuperAdmin()
            || in_array($u->department, ['qa_compliance', 'finance', 'executive', 'it_admin']),
            403
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $rows = ReportDefinition::forTenant($u->effectiveTenantId())->visibleTo($u->id)
            ->orderByDesc('updated_at')->limit(200)->get();
        return response()->json(['reports' => $rows]);
    }

    /** Phase 15-UI : custom report builder Inertia page. */
    public function builder()
    {
        $this->gate();
        $u = Auth::user();
        $rows = ReportDefinition::forTenant($u->effectiveTenantId())->visibleTo($u->id)
            ->orderByDesc('updated_at')->limit(200)->get();

        return \Inertia\Inertia::render('Reports/CustomBuilder', [
            'reports'  => $rows,
            'entities' => ReportDefinition::ENTITIES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $validated = $request->validate([
            'name'     => 'required|string|max:200',
            'entity'   => 'required|in:' . implode(',', ReportDefinition::ENTITIES),
            'filters'  => 'nullable|array',
            'columns'  => 'nullable|array',
            'group_by' => 'nullable|array',
            'is_shared'=> 'boolean',
        ]);
        $def = ReportDefinition::create(array_merge($validated, [
            'tenant_id'          => $u->effectiveTenantId(),
            'created_by_user_id' => $u->id,
        ]));
        AuditLog::record(
            action: 'report.definition_created',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'report_definition', resourceId: $def->id,
            description: "Report created: {$def->name} ({$def->entity})",
        );
        return response()->json(['report' => $def], 201);
    }

    public function run(ReportDefinition $definition): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($definition->tenant_id === $u->effectiveTenantId(), 404);

        $result = $this->runner->run($definition);
        AuditLog::record(
            action: 'report.definition_run',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'report_definition', resourceId: $definition->id,
            description: "Report run: {$definition->name} → {$result['total']} rows",
        );
        return response()->json($result);
    }

    public function download(ReportDefinition $definition)
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($definition->tenant_id === $u->effectiveTenantId(), 404);
        return $this->runner->toCsv($definition);
    }

    public function destroy(ReportDefinition $definition): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($definition->tenant_id === $u->effectiveTenantId(), 404);
        $definition->delete();
        return response()->json(['ok' => true]);
    }
}
