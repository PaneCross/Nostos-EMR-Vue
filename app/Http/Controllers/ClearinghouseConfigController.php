<?php

// ─── ClearinghouseConfigController ──────────────────────────────────────────
// Phase 12. IT-admin-only CRUD for per-tenant clearinghouse configuration.
// Mirrors the StateMedicaidConfigController pattern.
//
// Only ONE row per tenant may be active at a time — activating a new config
// deactivates any existing active row inside the same transaction.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ClearinghouseConfig;
use App\Services\Clearinghouse\ClearinghouseGatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ClearinghouseConfigController extends Controller
{
    public function __construct(private ClearinghouseGatewayFactory $factory) {}

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        abort_unless($u->isSuperAdmin() || $u->department === 'it_admin', 403);
    }

    public function index(Request $request)
    {
        $this->gate();
        $u = Auth::user();

        $rows = ClearinghouseConfig::forTenant($u->tenant_id)
            ->orderByDesc('is_active')
            ->orderByDesc('updated_at')
            ->get(['id', 'adapter', 'display_name', 'environment', 'submitter_id',
                   'receiver_id', 'endpoint_url', 'submission_timeout_s', 'max_retries',
                   'retry_backoff_s', 'notes', 'is_active',
                   'last_successful_at', 'last_failed_at', 'last_error']);

        if ($request->wantsJson()) {
            return response()->json([
                'configs'          => $rows,
                'available_adapters' => ClearinghouseConfig::ADAPTER_LABELS,
                'environments'     => ClearinghouseConfig::ENVIRONMENTS,
                'honest_label'     => 'The default "No vendor — manual upload" adapter is active by default. '
                    . 'Activating a real vendor adapter requires a signed trading-partner agreement. '
                    . 'Until then, all 837P batches are staged for manual upload.',
            ]);
        }

        return Inertia::render('ItAdmin/ClearinghouseConfig', [
            'configs'           => $rows,
            'availableAdapters' => ClearinghouseConfig::ADAPTER_LABELS,
            'environments'      => ClearinghouseConfig::ENVIRONMENTS,
            'honestLabel'       => 'Default is "No vendor — manual upload." A real adapter requires a signed trading-partner agreement.',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();

        $validated = $request->validate([
            'adapter'              => 'required|in:' . implode(',', ClearinghouseConfig::ADAPTERS),
            'display_name'         => 'required|string|max:120',
            'submitter_id'         => 'nullable|string|max:40',
            'receiver_id'          => 'nullable|string|max:40',
            'endpoint_url'         => 'nullable|url|max:255',
            'credentials_json'     => 'nullable|array',
            'environment'          => 'required|in:' . implode(',', ClearinghouseConfig::ENVIRONMENTS),
            'submission_timeout_s' => 'nullable|integer|min:5|max:600',
            'max_retries'          => 'nullable|integer|min:0|max:10',
            'retry_backoff_s'      => 'nullable|integer|min:1|max:3600',
            'notes'                => 'nullable|string|max:2000',
            'is_active'            => 'nullable|boolean',
        ]);

        $row = DB::transaction(function () use ($u, $validated) {
            if ($validated['is_active'] ?? false) {
                ClearinghouseConfig::forTenant($u->tenant_id)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }
            return ClearinghouseConfig::create(array_merge($validated, [
                'tenant_id' => $u->tenant_id,
            ]));
        });

        AuditLog::record(
            action: 'clearinghouse.config_created',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'clearinghouse_config',
            resourceId: $row->id,
            description: "Clearinghouse config created: {$row->adapter} ({$row->display_name})",
        );

        return response()->json(['config' => $row], 201);
    }

    public function update(Request $request, ClearinghouseConfig $config): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($config->tenant_id === $u->tenant_id, 404);

        $validated = $request->validate([
            'adapter'              => 'sometimes|in:' . implode(',', ClearinghouseConfig::ADAPTERS),
            'display_name'         => 'sometimes|string|max:120',
            'submitter_id'         => 'nullable|string|max:40',
            'receiver_id'          => 'nullable|string|max:40',
            'endpoint_url'         => 'nullable|url|max:255',
            'credentials_json'     => 'nullable|array',
            'environment'          => 'sometimes|in:' . implode(',', ClearinghouseConfig::ENVIRONMENTS),
            'submission_timeout_s' => 'nullable|integer|min:5|max:600',
            'max_retries'          => 'nullable|integer|min:0|max:10',
            'retry_backoff_s'      => 'nullable|integer|min:1|max:3600',
            'notes'                => 'nullable|string|max:2000',
            'is_active'            => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($u, $config, $validated) {
            if (($validated['is_active'] ?? false) === true) {
                ClearinghouseConfig::forTenant($u->tenant_id)
                    ->where('id', '!=', $config->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }
            $config->update($validated);
        });

        AuditLog::record(
            action: 'clearinghouse.config_updated',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'clearinghouse_config',
            resourceId: $config->id,
            description: 'Clearinghouse config updated',
        );

        return response()->json(['config' => $config->fresh()]);
    }

    public function healthCheck(ClearinghouseConfig $config): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($config->tenant_id === $u->tenant_id, 404);

        $gateway = $this->factory->resolve($config->adapter);
        $ok = $gateway->healthCheck($config);

        return response()->json([
            'ok'      => $ok,
            'adapter' => $gateway->name(),
            'message' => $ok
                ? 'Adapter reports healthy.'
                : 'Adapter is a scaffold — activation requires a signed vendor contract.',
        ]);
    }

    public function destroy(ClearinghouseConfig $config): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_unless($config->tenant_id === $u->tenant_id, 404);

        $config->delete();

        AuditLog::record(
            action: 'clearinghouse.config_deleted',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'clearinghouse_config',
            resourceId: $config->id,
            description: 'Clearinghouse config deleted',
        );

        return response()->json(['ok' => true]);
    }
}
