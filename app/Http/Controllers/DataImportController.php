<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DataImport;
use App\Services\DataImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class DataImportController extends Controller
{
    public function __construct(private DataImportService $svc) {}

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        abort_unless(
            $u->isSuperAdmin() || in_array($u->department, ['it_admin', 'enrollment', 'qa_compliance']),
            403
        );
    }

    public function template(Request $request, string $entity)
    {
        $this->gate();
        abort_unless(in_array($entity, DataImport::ENTITIES, true), 404);
        return response($this->svc->template($entity), 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="import-template-' . $entity . '.csv"',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $validated = $request->validate([
            'entity' => 'required|in:' . implode(',', DataImport::ENTITIES),
            'file'   => 'required|file|mimes:csv,txt|max:10240',
        ]);
        $path = $request->file('file')->storeAs(
            'data-imports/tenant-' . $u->tenant_id,
            'import-' . now()->format('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.csv'
        );
        $import = DataImport::create([
            'tenant_id'           => $u->tenant_id,
            'uploaded_by_user_id' => $u->id,
            'entity'              => $validated['entity'],
            'status'              => 'staged',
            'original_filename'   => $request->file('file')->getClientOriginalName(),
            'stored_path'         => $path,
        ]);
        $parsed = $this->svc->parseCsv($import);
        AuditLog::record(
            action: 'data_import.staged',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'data_import', resourceId: $import->id,
            description: "Data import staged: {$import->entity} · {$parsed['row_count']} rows",
        );
        return response()->json(['import' => $import->fresh(), 'preview' => $parsed], 201);
    }

    public function commit(DataImport $import): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($import->tenant_id === $u->tenant_id, 404);
        $result = $this->svc->commit($import);
        AuditLog::record(
            action: 'data_import.committed',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'data_import', resourceId: $import->id,
            description: "Data import committed: {$result['inserted']} rows inserted, " . count($result['errors']) . " errors",
        );
        return response()->json(['import' => $import->fresh(), 'result' => $result]);
    }

    public function index(Request $request)
    {
        $this->gate();
        $u = Auth::user();
        $rows = DataImport::forTenant($u->tenant_id)
            ->orderByDesc('created_at')->limit(100)->get();

        if ($request->wantsJson()) {
            return response()->json(['imports' => $rows]);
        }
        return \Inertia\Inertia::render('DataImports/Index', [
            'imports'  => $rows,
            'entities' => DataImport::ENTITIES,
            'templates'=> \App\Services\DataImportService::TEMPLATE_HEADERS,
        ]);
    }
}
