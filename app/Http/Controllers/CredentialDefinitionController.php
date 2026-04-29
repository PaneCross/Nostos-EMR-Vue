<?php

// ─── CredentialDefinitionController ──────────────────────────────────────────
// Executive-gated CRUD for the org's credential catalog. CMS-mandatory rows
// (seeded by CmsCredentialBaselineSeeder) cannot be deleted or have their
// is_cms_mandatory / code fields edited; title / description / cadence / PSV
// / targeting all remain editable.
//
// Endpoints (all under /executive/credential-definitions):
//   GET  /        : list catalog
//   POST /        : create
//   PATCH /{id}   : update + replace targets
//   DELETE /{id}  : soft-delete (rejected for is_cms_mandatory rows)
//
// Site overrides:
//   POST   /executive/credential-definitions/{id}/site-overrides
//   DELETE /executive/credential-definitions/{id}/site-overrides/{site_id}
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CredentialDefinition;
use App\Models\CredentialDefinitionSiteOverride;
use App\Models\CredentialDefinitionTarget;
use App\Models\JobTitle;
use App\Models\Site;
use App\Models\StaffCredential;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CredentialDefinitionController extends Controller
{
    /** Inertia page : the actual catalog UI. Loads data via JSON endpoints. */
    public function page(Request $request): InertiaResponse
    {
        $this->gate($request);
        return Inertia::render('Executive/CredentialsCatalog');
    }

    private function gate(Request $request): void
    {
        $u = $request->user();
        abort_unless($u, 401);
        abort_unless(
            $u->isSuperAdmin() || $u->department === 'executive',
            403,
            'Only Executive (or Super Admin) may manage credential definitions.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;

        $definitions = CredentialDefinition::forTenant($tenantId)
            ->with(['targets', 'siteOverrides.site:id,name'])
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->map(fn ($d) => $this->presentDefinition($d));

        return response()->json([
            'definitions'   => $definitions,
            'departments'   => User::DEPARTMENTS_LIST,
            'jobTitles'     => JobTitle::forTenant($tenantId)->active()->orderBy('sort_order')->get(['code', 'label']),
            'designations'  => User::DESIGNATIONS,
            'sites'         => Site::where('tenant_id', $tenantId)->get(['id', 'name']),
            'credentialTypes' => StaffCredential::TYPE_LABELS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;

        $v = $this->validatePayload($request, $tenantId, null);

        $def = DB::transaction(function () use ($v, $tenantId, $request) {
            $def = CredentialDefinition::create([
                'tenant_id'              => $tenantId,
                'site_id'                => $v['site_id'] ?? null,
                'code'                   => $v['code'],
                'title'                  => $v['title'],
                'credential_type'        => $v['credential_type'],
                'description'            => $v['description'] ?? null,
                'requires_psv'           => $v['requires_psv'] ?? false,
                'is_cms_mandatory'       => false,   // only seeder can mint mandatory rows
                'default_doc_required'   => $v['default_doc_required'] ?? false,
                'reminder_cadence_days'  => $v['reminder_cadence_days'] ?? CredentialDefinition::DEFAULT_CADENCE,
                'ceu_hours_required'     => $v['ceu_hours_required'] ?? 0,
                'is_active'              => $v['is_active'] ?? true,
                'sort_order'             => $v['sort_order'] ?? 100,
            ]);

            $this->syncTargets($def, $v['targets'] ?? []);

            AuditLog::record(
                action: 'credential_definition.created',
                resourceType: 'CredentialDefinition',
                resourceId: $def->id,
                tenantId: $tenantId,
                userId: $request->user()->id,
                newValues: $def->toArray(),
            );

            return $def;
        });

        return response()->json($this->presentDefinition($def->load(['targets', 'siteOverrides'])), 201);
    }

    public function update(Request $request, CredentialDefinition $credentialDefinition): JsonResponse
    {
        $this->gate($request);
        abort_if($credentialDefinition->tenant_id !== $request->user()->tenant_id, 403);

        $v = $this->validatePayload($request, $request->user()->tenant_id, $credentialDefinition);

        DB::transaction(function () use ($credentialDefinition, $v, $request) {
            $old = $credentialDefinition->toArray();

            $patch = [];
            foreach (['title', 'description', 'requires_psv', 'default_doc_required',
                      'reminder_cadence_days', 'ceu_hours_required', 'is_active', 'sort_order'] as $field) {
                if (array_key_exists($field, $v)) $patch[$field] = $v[$field];
            }

            // CMS-mandatory rows : cannot change code or credential_type or is_cms_mandatory
            if (! $credentialDefinition->is_cms_mandatory) {
                if (array_key_exists('code', $v)) $patch['code'] = $v['code'];
                if (array_key_exists('credential_type', $v)) $patch['credential_type'] = $v['credential_type'];
            }

            $credentialDefinition->update($patch);

            if (array_key_exists('targets', $v)) {
                $this->syncTargets($credentialDefinition, $v['targets']);
            }

            AuditLog::record(
                action: 'credential_definition.updated',
                resourceType: 'CredentialDefinition',
                resourceId: $credentialDefinition->id,
                tenantId: $request->user()->tenant_id,
                userId: $request->user()->id,
                oldValues: $old,
                newValues: $credentialDefinition->fresh()->toArray(),
            );
        });

        return response()->json($this->presentDefinition($credentialDefinition->fresh()->load(['targets', 'siteOverrides.site:id,name'])));
    }

    /**
     * D12 : render the CredentialExpiringMail HTML for preview from the
     * catalog edit modal. Lets executives sanity-check what their staff
     * will receive at each cadence step before they save the definition.
     */
    public function previewEmail(Request $request, CredentialDefinition $credentialDefinition): \Illuminate\Http\Response
    {
        $this->gate($request);
        abort_if($credentialDefinition->tenant_id !== $request->user()->tenant_id, 403);

        $days = (int) $request->query('days', 30);
        $isSupervisor = (bool) $request->query('supervisor', false);

        // Synthesize a mock credential + user so the Mailable has data to render.
        $user = $request->user();
        $mockCredential = new \App\Models\StaffCredential([
            'tenant_id' => $credentialDefinition->tenant_id,
            'user_id'   => $user->id,
            'credential_type' => $credentialDefinition->credential_type,
            'title'     => $credentialDefinition->title,
            'expires_at'=> now()->addDays($days)->toDateString(),
        ]);
        $mockCredential->setRelation('user', $user);
        $mockCredential->id = 0;

        $mailable = new \App\Mail\CredentialExpiringMail($user, $mockCredential, $days, $isSupervisor);
        $html = $mailable->render();

        return response($html)->header('Content-Type', 'text/html');
    }

    public function destroy(Request $request, CredentialDefinition $credentialDefinition): JsonResponse
    {
        $this->gate($request);
        abort_if($credentialDefinition->tenant_id !== $request->user()->tenant_id, 403);

        if ($credentialDefinition->is_cms_mandatory) {
            return response()->json([
                'message' => 'CMS-mandatory definitions cannot be deleted.',
            ], 422);
        }

        $credentialDefinition->delete();

        AuditLog::record(
            action: 'credential_definition.deleted',
            resourceType: 'CredentialDefinition',
            resourceId: $credentialDefinition->id,
            tenantId: $request->user()->tenant_id,
            userId: $request->user()->id,
        );

        return response()->json(['ok' => true]);
    }

    public function storeSiteOverride(Request $request, CredentialDefinition $credentialDefinition): JsonResponse
    {
        $this->gate($request);
        abort_if($credentialDefinition->tenant_id !== $request->user()->tenant_id, 403);

        if ($credentialDefinition->is_cms_mandatory) {
            return response()->json([
                'message' => 'CMS-mandatory definitions cannot be disabled per-site. They are required everywhere.',
            ], 422);
        }

        $v = $request->validate([
            'site_id' => ['required', 'integer',
                Rule::exists('shared_sites', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        $override = CredentialDefinitionSiteOverride::updateOrCreate(
            [
                'tenant_id'                 => $request->user()->tenant_id,
                'site_id'                   => $v['site_id'],
                'credential_definition_id'  => $credentialDefinition->id,
            ],
            [
                'action'             => 'disabled',
                'updated_by_user_id' => $request->user()->id,
            ]
        );

        AuditLog::record(
            action: 'credential_definition.site_disabled',
            resourceType: 'CredentialDefinition',
            resourceId: $credentialDefinition->id,
            tenantId: $request->user()->tenant_id,
            userId: $request->user()->id,
            newValues: ['site_id' => $v['site_id']],
        );

        return response()->json($override->load('site:id,name'), 201);
    }

    public function destroySiteOverride(
        Request $request,
        CredentialDefinition $credentialDefinition,
        int $siteId
    ): JsonResponse {
        $this->gate($request);
        abort_if($credentialDefinition->tenant_id !== $request->user()->tenant_id, 403);

        CredentialDefinitionSiteOverride::where('credential_definition_id', $credentialDefinition->id)
            ->where('site_id', $siteId)
            ->delete();

        AuditLog::record(
            action: 'credential_definition.site_re_enabled',
            resourceType: 'CredentialDefinition',
            resourceId: $credentialDefinition->id,
            tenantId: $request->user()->tenant_id,
            userId: $request->user()->id,
            newValues: ['site_id' => $siteId],
        );

        return response()->json(['ok' => true]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function validatePayload(Request $request, int $tenantId, ?CredentialDefinition $existing): array
    {
        $codeRule = ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/',
            Rule::unique('emr_credential_definitions', 'code')
                ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at'))
                ->ignore($existing?->id),
        ];

        // B5 : PSV only logically applies to licensure-grade credentials.
        // Validate cross-field : if requires_psv is true, credential_type must
        // be license / certification / driver_record / background_check (the
        // four types where a state board / NPDB / federal database actually
        // verifies).
        $request->validate([
            'requires_psv'    => ['nullable', 'boolean'],
            'credential_type' => ['nullable', Rule::in(array_keys(StaffCredential::TYPE_LABELS))],
        ]);
        $reqPsv = $request->input('requires_psv', false);
        $type   = $request->input('credential_type');
        if ($reqPsv && ! in_array($type, ['license', 'certification', 'driver_record', 'background_check'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'requires_psv' => "Primary-source verification only applies to license, certification, driver_record, or background_check types. The selected type '{$type}' does not have a verifying authority.",
            ]);
        }

        return $request->validate([
            'code'                   => $existing && $existing->is_cms_mandatory ? ['nullable'] : ['required', ...array_slice($codeRule, 1)],
            'title'                  => ['required', 'string', 'max:200'],
            'credential_type'        => ['required', Rule::in(array_keys(StaffCredential::TYPE_LABELS))],
            'description'            => ['nullable', 'string', 'max:2000'],
            'site_id'                => ['nullable', 'integer',
                Rule::exists('shared_sites', 'id')->where('tenant_id', $tenantId)],
            'requires_psv'           => ['nullable', 'boolean'],
            'default_doc_required'   => ['nullable', 'boolean'],
            'reminder_cadence_days'  => ['nullable', 'array'],
            'reminder_cadence_days.*'=> ['integer', 'min:-30', 'max:365'],
            'ceu_hours_required'     => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active'              => ['nullable', 'boolean'],
            'sort_order'             => ['nullable', 'integer', 'min:0', 'max:9999'],
            'targets'                => ['nullable', 'array'],
            'targets.*.kind'         => ['required_with:targets', Rule::in(['department', 'job_title', 'designation'])],
            'targets.*.value'        => ['required_with:targets', 'string', 'max:80'],
        ]);
    }

    private function syncTargets(CredentialDefinition $def, array $targets): void
    {
        CredentialDefinitionTarget::where('credential_definition_id', $def->id)->delete();

        foreach ($targets as $t) {
            CredentialDefinitionTarget::create([
                'credential_definition_id' => $def->id,
                'target_kind'              => $t['kind'],
                'target_value'             => $t['value'],
            ]);
        }
    }

    private function presentDefinition(CredentialDefinition $d): array
    {
        return [
            'id'                     => $d->id,
            'tenant_id'              => $d->tenant_id,
            'site_id'                => $d->site_id,
            'code'                   => $d->code,
            'title'                  => $d->title,
            'credential_type'        => $d->credential_type,
            'credential_type_label'  => StaffCredential::TYPE_LABELS[$d->credential_type] ?? $d->credential_type,
            'description'            => $d->description,
            'requires_psv'           => $d->requires_psv,
            'is_cms_mandatory'       => $d->is_cms_mandatory,
            'default_doc_required'   => $d->default_doc_required,
            'reminder_cadence_days'  => $d->reminder_cadence_days,
            'ceu_hours_required'     => (int) $d->ceu_hours_required,
            'is_active'              => $d->is_active,
            'sort_order'             => $d->sort_order,
            'targets'                => $d->targets->map(fn ($t) => [
                'kind'  => $t->target_kind,
                'value' => $t->target_value,
            ])->values(),
            'site_overrides'         => $d->siteOverrides->map(fn ($o) => [
                'site_id'   => $o->site_id,
                'site_name' => $o->site?->name,
                'action'    => $o->action,
            ])->values(),
        ];
    }
}
