<?php

// ─── StaffCredentialController ────────────────────────────────────────────────
// Credentials + training-record CRUD on a single staff user.
//
// Routes:
//   GET  /it-admin/users/{user}/credentials      : Inertia page
//   POST /it-admin/users/{user}/credentials      : add credential
//   PATCH /staff-credentials/{credential}        : update credential
//   DELETE /staff-credentials/{credential}       : soft-delete
//   POST /it-admin/users/{user}/training         : add training record
//   DELETE /staff-training/{record}              : soft-delete
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CredentialDefinition;
use App\Services\Credentials\CredentialDefinitionService;
use App\Models\StaffCredential;
use App\Models\StaffTrainingRecord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class StaffCredentialController extends Controller
{
    /** Gate for write actions: IT admin, super admin, QA compliance. */
    private function gate(Request $request): void
    {
        $u = $request->user();
        abort_unless($u, 401);
        abort_unless(
            $u->isSuperAdmin()
                || in_array($u->department, ['it_admin', 'qa_compliance'], true),
            403,
            'Only IT Admin, QA Compliance, and Super Admin may manage staff credentials.'
        );
    }

    /**
     * Read-gate : also allows Executive (read-only via the index page) so the
     * exec can see a staff member's credentials when investigating dashboard
     * findings without needing to ping IT Admin. Write endpoints still use
     * the stricter gate().
     */
    private function readGate(Request $request): void
    {
        $u = $request->user();
        abort_unless($u, 401);
        abort_unless(
            $u->isSuperAdmin()
                || in_array($u->department, ['it_admin', 'qa_compliance', 'executive'], true),
            403,
            'Only IT Admin, QA Compliance, Executive, or Super Admin may view staff credentials.'
        );
    }

    /** Scope: same tenant as the acting user. */
    private function assertSameTenant(User $staff, Request $request): void
    {
        abort_if($staff->tenant_id !== $request->user()->tenant_id, 403);
    }

    public function index(Request $request, User $user): InertiaResponse
    {
        // Read-gate : Executive can VIEW the page (read-only) ; only IT Admin /
        // QA Compliance / Super Admin can mutate via the write endpoints.
        $this->readGate($request);
        $this->assertSameTenant($user, $request);

        $credentials = StaffCredential::forTenant($user->tenant_id)
            ->where('user_id', $user->id)
            ->with('definition:id,ceu_hours_required')
            ->orderByRaw('expires_at IS NULL ASC, expires_at ASC')
            ->get()
            ->map(fn (StaffCredential $c) => [
                'id'               => $c->id,
                'credential_definition_id' => $c->credential_definition_id,
                'credential_type'  => $c->credential_type,
                'type_label'       => StaffCredential::TYPE_LABELS[$c->credential_type] ?? $c->credential_type,
                'title'            => $c->title,
                'license_state'    => $c->license_state,
                'license_number'   => $c->license_number,
                'issued_at'        => $c->issued_at?->toDateString(),
                'expires_at'       => $c->expires_at?->toDateString(),
                'days_remaining'   => $c->daysUntilExpiration(),
                'status'           => $c->status(),
                'cms_status'       => $c->cms_status,
                'verification_source' => $c->verification_source,
                'verified_at'      => $c->verified_at?->toIso8601String(),
                'replaced_by_credential_id' => $c->replaced_by_credential_id,
                'is_superseded'    => $c->replaced_by_credential_id !== null,
                // V2: driver-specific fields (only meaningful for type=driver_record)
                'dot_medical_card_expires_at' => $c->dot_medical_card_expires_at?->toDateString(),
                'mvr_check_date'              => $c->mvr_check_date?->toDateString(),
                'vehicle_class_endorsements'  => $c->vehicle_class_endorsements,
                'ceu_hours_logged'   => $c->ceuHoursLogged(),
                'ceu_hours_required' => (int) ($c->definition?->ceu_hours_required ?? 0),
                'notes'              => $c->notes,
            ]);

        $training = StaffTrainingRecord::forTenant($user->tenant_id)
            ->where('user_id', $user->id)
            ->with('credential:id,title')
            ->orderByDesc('completed_at')
            ->get()
            ->map(fn (StaffTrainingRecord $r) => [
                'id'             => $r->id,
                'training_name'  => $r->training_name,
                'category'       => $r->category,
                'category_label' => StaffTrainingRecord::CATEGORY_LABELS[$r->category] ?? $r->category,
                'training_hours' => (float) $r->training_hours,
                'completed_at'   => $r->completed_at?->toDateString(),
                'verified_at'    => $r->verified_at?->toIso8601String(),
                'notes'          => $r->notes,
                // V2 : counts toward this credential's CEU cycle (or null if standalone)
                'credential_id'   => $r->credential_id,
                'credential_title'=> $r->credential?->title,
            ]);

        // Training hours totals: past 12 months, grouped by category.
        $since = Carbon::now()->subYear()->toDateString();
        $hoursByCategory = StaffTrainingRecord::forTenant($user->tenant_id)
            ->where('user_id', $user->id)
            ->where('completed_at', '>=', $since)
            ->selectRaw('category, SUM(training_hours) as total_hours')
            ->groupBy('category')
            ->pluck('total_hours', 'category')
            ->map(fn ($v) => (float) $v)
            ->toArray();

        $totalHours = (float) array_sum($hoursByCategory);

        // Resolve which definitions apply to this user, and which are missing
        $defService = app(CredentialDefinitionService::class);
        $applicable = $defService->activeForUser($user);
        $missing    = $defService->missingForUser($user);

        return Inertia::render('ItAdmin/StaffCredentials', [
            'staff' => [
                'id'         => $user->id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
                'department' => $user->department,
                'role'       => $user->role,
                'job_title'  => $user->job_title,
                'is_active'  => (bool) $user->is_active,
            ],
            'credentials'     => $credentials->map(fn ($c) => $c + [
                'document_url' => $c['id'] && ($url = $this->documentUrlFor($c['id'])) ? $url : null,
            ]),
            'training'        => $training,
            'hoursByCategory' => $hoursByCategory,
            'totalHours12mo'  => round($totalHours, 2),
            'credentialTypes' => StaffCredential::TYPE_LABELS,
            'trainingCategories' => StaffTrainingRecord::CATEGORY_LABELS,
            'applicableDefinitions' => $applicable->map(fn ($d) => [
                'id'     => $d->id,
                'code'   => $d->code,
                'title'  => $d->title,
                'credential_type' => $d->credential_type,
                'requires_psv'    => $d->requires_psv,
                'is_cms_mandatory'=> $d->is_cms_mandatory,
                'default_doc_required' => $d->default_doc_required,
            ])->values(),
            'missingDefinitions' => $missing->map(fn ($d) => [
                'id' => $d->id, 'code' => $d->code, 'title' => $d->title,
                'is_cms_mandatory' => $d->is_cms_mandatory,
            ])->values(),
            'verificationSources' => StaffCredential::VERIFICATION_SOURCES,
            'cmsStatuses'         => StaffCredential::CMS_STATUSES,
            'canEdit' => $request->user()->isSuperAdmin()
                || in_array($request->user()->department, ['it_admin', 'qa_compliance'], true),
        ]);
    }

    private function documentUrlFor(int $credentialId): ?string
    {
        $cred = StaffCredential::find($credentialId);
        if (! $cred?->document_path) return null;

        // Generate a signed temporary URL for inline preview / download
        return route('staff-credentials.document', ['credential' => $credentialId]);
    }

    public function downloadDocument(Request $request, StaffCredential $credential)
    {
        $u = $request->user();
        abort_unless($u, 401);
        // Allow: admin/QA, OR the user themselves viewing their own doc
        $isAdmin = $u->isSuperAdmin() || in_array($u->department, ['it_admin', 'qa_compliance', 'executive'], true);
        $isSelf  = $u->id === $credential->user_id;
        abort_unless($isAdmin || $isSelf, 403);
        abort_if($credential->tenant_id !== $u->tenant_id, 403);
        abort_unless($credential->document_path && Storage::disk('local')->exists($credential->document_path), 404);

        return response()->file(
            Storage::disk('local')->path($credential->document_path),
            ['Content-Type' => 'application/pdf']
        );
    }

    public function storeCredential(Request $request, User $user): JsonResponse
    {
        $this->gate($request);
        $this->assertSameTenant($user, $request);

        $v = $request->validate([
            'credential_definition_id' => ['nullable', 'integer',
                Rule::exists('emr_credential_definitions', 'id')->where('tenant_id', $user->tenant_id)],
            'credential_type' => ['required', Rule::in(StaffCredential::TYPES)],
            'title'           => ['required', 'string', 'max:200'],
            'license_state'   => ['nullable', 'string', 'size:2'],
            'license_number'  => ['nullable', 'string', 'max:80'],
            'issued_at'       => ['nullable', 'date'],
            'expires_at'      => ['nullable', 'date', 'after_or_equal:issued_at'],
            'verification_source' => ['nullable', Rule::in(array_keys(StaffCredential::VERIFICATION_SOURCES))],
            'cms_status'      => ['nullable', Rule::in(array_keys(StaffCredential::CMS_STATUSES))],
            'dot_medical_card_expires_at' => ['nullable', 'date'],
            'mvr_check_date'              => ['nullable', 'date'],
            'vehicle_class_endorsements'  => ['nullable', 'string', 'max:200'],
            'notes'           => ['nullable', 'string', 'max:4000'],
            'document'        => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        // Enforce definition-driven requirements (doc_required + requires_psv).
        // The catalog tags weren't being checked server-side ; an admin could
        // add a "Doc Required" credential without attaching a doc.
        $this->enforceDefinitionRules(
            tenantId: $user->tenant_id,
            definitionId: $v['credential_definition_id'] ?? null,
            hasDocument: $request->hasFile('document'),
            verificationSource: $v['verification_source'] ?? null,
            existingDocumentPath: null,
        );

        $c = StaffCredential::create(array_merge(
            collect($v)->except('document')->all(),
            [
                'tenant_id'           => $user->tenant_id,
                'user_id'             => $user->id,
                'cms_status'          => $v['cms_status'] ?? 'active',
                'verified_at'         => now(),
                'verified_by_user_id' => $request->user()->id,
            ]
        ));

        if ($request->hasFile('document')) {
            $this->storeDocument($c, $request->file('document'));
        }

        AuditLog::record(
            action: 'staff_credential.created',
            resourceType: 'StaffCredential',
            resourceId: $c->id,
            tenantId: $user->tenant_id,
            userId: $request->user()->id,
            newValues: $c->toArray(),
        );

        return response()->json($c->fresh(), 201);
    }

    public function updateCredential(Request $request, StaffCredential $credential): JsonResponse
    {
        $this->gate($request);
        abort_if($credential->tenant_id !== $request->user()->tenant_id, 403);

        $v = $request->validate([
            'credential_definition_id' => ['nullable', 'integer',
                Rule::exists('emr_credential_definitions', 'id')->where('tenant_id', $credential->tenant_id)],
            'title'          => ['sometimes', 'string', 'max:200'],
            'license_state'  => ['nullable', 'string', 'size:2'],
            'license_number' => ['nullable', 'string', 'max:80'],
            'issued_at'      => ['nullable', 'date'],
            'expires_at'     => ['nullable', 'date'],
            'verification_source' => ['nullable', Rule::in(array_keys(StaffCredential::VERIFICATION_SOURCES))],
            'cms_status'     => ['nullable', Rule::in(array_keys(StaffCredential::CMS_STATUSES))],
            'dot_medical_card_expires_at' => ['nullable', 'date'],
            'mvr_check_date'              => ['nullable', 'date'],
            'vehicle_class_endorsements'  => ['nullable', 'string', 'max:200'],
            'notes'          => ['nullable', 'string', 'max:4000'],
            'document'       => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        // Enforce definition-driven requirements on update too. For doc_required
        // we accept either a fresh upload OR an existing document_path on the
        // record (we don't force re-upload if a doc was uploaded previously).
        $defIdForCheck = array_key_exists('credential_definition_id', $v)
            ? $v['credential_definition_id']
            : $credential->credential_definition_id;
        $this->enforceDefinitionRules(
            tenantId: $credential->tenant_id,
            definitionId: $defIdForCheck,
            hasDocument: $request->hasFile('document'),
            verificationSource: $v['verification_source'] ?? $credential->verification_source,
            existingDocumentPath: $credential->document_path,
        );

        $old = $credential->toArray();
        $credential->update(collect($v)->except('document')->all());

        if ($request->hasFile('document')) {
            $this->storeDocument($credential, $request->file('document'));
        }

        AuditLog::record(
            action: 'staff_credential.updated',
            resourceType: 'StaffCredential',
            resourceId: $credential->id,
            tenantId: $credential->tenant_id,
            userId: $request->user()->id,
            oldValues: $old,
            newValues: $credential->fresh()->toArray(),
        );

        return response()->json($credential->fresh());
    }

    /**
     * Enforce per-definition rules : if the definition flags doc_required
     * and/or requires_psv, the submission must satisfy them. Throws a 422
     * with field-level errors when not satisfied.
     *
     * Free-form rows (no definition_id) skip these checks entirely : the
     * catalog rules only bind when a row is linked to a definition.
     */
    private function enforceDefinitionRules(
        int $tenantId,
        ?int $definitionId,
        bool $hasDocument,
        ?string $verificationSource,
        ?string $existingDocumentPath,
    ): void {
        if (! $definitionId) return;
        $def = CredentialDefinition::forTenant($tenantId)->find($definitionId);
        if (! $def) return;

        $errors = [];

        if ($def->default_doc_required && ! $hasDocument && ! $existingDocumentPath) {
            $errors['document'] = ["This credential requires a supporting document. Attach a PDF, JPG, or PNG."];
        }

        if ($def->requires_psv) {
            $valid = ! empty($verificationSource)
                && in_array($verificationSource, ['state_board', 'npdb'], true);
            if (! $valid) {
                $errors['verification_source'] = [
                    'This credential requires primary-source verification : choose '
                    . '"State Licensing Board" or "NPDB Lookup" as the verification source. '
                    . 'Self-attestation or uploaded-document is not sufficient.'
                ];
            }
        }

        if (! empty($errors)) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    /** Save uploaded doc to local disk under tenant/{id}/credentials/{cred_id}.{ext}. */
    private function storeDocument(StaffCredential $credential, $file): void
    {
        // Audit #2 A5 : purge the previous file before overwriting the path
        // pointer so we don't leak orphaned PDFs on disk.
        if ($credential->document_path && Storage::disk('local')->exists($credential->document_path)) {
            Storage::disk('local')->delete($credential->document_path);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        $dir = "credentials/tenant_{$credential->tenant_id}/user_{$credential->user_id}";
        $name = "cred_{$credential->id}_" . now()->format('YmdHis') . ".{$ext}";

        $path = $file->storeAs($dir, $name, 'local');

        $credential->update([
            'document_path'     => $path,
            'document_filename' => $file->getClientOriginalName(),
        ]);
    }

    /**
     * D6 : bulk-renew a set of credentials to the same new expires_at. Used
     * after a group event (annual fire-drill, BLS class graduation, OIG/SAM
     * monthly run) where many staff get the same new expiration date.
     *
     * Accepts credential_ids[] + new_expires_at + optional new_issued_at +
     * verification_source. Each row goes through the same versioning chain
     * as a self-renewal : new row created, old row marked superseded. Sets
     * cms_status='active' immediately since IT Admin is doing the bulk
     * action and presumably has the documentation in hand.
     */
    public function bulkRenew(Request $request): JsonResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;

        $v = $request->validate([
            'credential_ids'      => ['required', 'array', 'min:1', 'max:200'],
            'credential_ids.*'    => ['integer'],
            'new_expires_at'      => ['required', 'date', 'after:today'],
            'new_issued_at'       => ['nullable', 'date', 'before_or_equal:new_expires_at'],
            'verification_source' => ['nullable', Rule::in(array_keys(StaffCredential::VERIFICATION_SOURCES))],
        ]);

        $renewedIds = [];
        // Audit-4 A2 : lockForUpdate inside the transaction prevents two
        // concurrent bulkRenew calls from racing on the same credential row.
        \Illuminate\Support\Facades\DB::transaction(function () use ($v, $request, &$renewedIds, $tenantId) {
            $rows = StaffCredential::forTenant($tenantId)
                ->whereIn('id', $v['credential_ids'])
                ->whereNull('replaced_by_credential_id')
                ->lockForUpdate()
                ->get();

            foreach ($rows as $old) {
                $newRow = StaffCredential::create([
                    'tenant_id'                => $old->tenant_id,
                    'user_id'                  => $old->user_id,
                    'credential_definition_id' => $old->credential_definition_id,
                    'credential_type'          => $old->credential_type,
                    'title'                    => $old->title,
                    'license_state'            => $old->license_state,
                    'license_number'           => $old->license_number,
                    'issued_at'                => $v['new_issued_at'] ?? now()->toDateString(),
                    'expires_at'               => $v['new_expires_at'],
                    'verification_source'      => $v['verification_source'] ?? 'uploaded_doc',
                    'cms_status'               => 'active',
                    'verified_at'              => now(),
                    'verified_by_user_id'      => $request->user()->id,
                    'notes'                    => 'Bulk renewal by ' . $request->user()->first_name . ' ' . $request->user()->last_name,
                ]);
                $old->update(['replaced_by_credential_id' => $newRow->id]);
                $renewedIds[] = $newRow->id;
            }
        });

        AuditLog::record(
            action: 'staff_credential.bulk_renewed',
            resourceType: 'StaffCredential',
            resourceId: 0,
            tenantId: $tenantId,
            userId: $request->user()->id,
            newValues: ['credential_count' => count($renewedIds), 'new_expires_at' => $v['new_expires_at']],
        );

        return response()->json(['ok' => true, 'renewed_count' => count($renewedIds)]);
    }

    /**
     * F3 : list soft-deleted credentials per user (recovery UI). Returns rows
     * with deleted_at + the deleting user when available. Read-only.
     */
    public function trashedForUser(Request $request, User $user): JsonResponse
    {
        $this->readGate($request);
        $this->assertSameTenant($user, $request);

        $rows = StaffCredential::onlyTrashed()
            ->forTenant($user->tenant_id)
            ->where('user_id', $user->id)
            ->orderByDesc('deleted_at')
            ->get([
                'id', 'credential_type', 'title', 'license_state', 'license_number',
                'issued_at', 'expires_at', 'cms_status', 'verification_source',
                'deleted_at', 'document_path', 'notes',
            ])
            ->map(fn ($c) => [
                'id'                  => $c->id,
                'title'               => $c->title,
                'type_label'          => StaffCredential::TYPE_LABELS[$c->credential_type] ?? $c->credential_type,
                'license_state'       => $c->license_state,
                'license_number'      => $c->license_number,
                'issued_at'           => $c->issued_at?->toDateString(),
                'expires_at'          => $c->expires_at?->toDateString(),
                'cms_status'          => $c->cms_status,
                'verification_source' => $c->verification_source,
                'deleted_at'          => $c->deleted_at?->toIso8601String(),
                'has_document'        => (bool) $c->document_path,
                'notes'               => $c->notes,
            ]);

        return response()->json($rows);
    }

    /**
     * F3 : restore a soft-deleted credential. Only IT Admin / Super Admin.
     * The audit log records the restore for HIPAA traceability.
     */
    public function restoreCredential(Request $request, int $credentialId): JsonResponse
    {
        $this->gate($request);
        $cred = StaffCredential::onlyTrashed()
            ->forTenant($request->user()->tenant_id)
            ->findOrFail($credentialId);

        $cred->restore();

        AuditLog::record(
            action: 'staff_credential.restored',
            resourceType: 'StaffCredential',
            resourceId: $cred->id,
            tenantId: $cred->tenant_id,
            userId: $request->user()->id,
            newValues: ['restored_from' => $cred->deleted_at?->toIso8601String()],
        );

        return response()->json(['ok' => true, 'credential' => $cred->fresh()]);
    }

    /**
     * G1 : bulk-edit fields on selected credentials WITHOUT the renewal-chain
     * versioning. Used for non-renewal updates : "we just got NPDB enrolled,
     * mark all licensure rows as verified via NPDB" or "CMS audit 2026 : add
     * a notes annotation."
     *
     * Accepts credential_ids[] + any of : verification_source, notes_append,
     * cms_status. Each is optional ; only fields present in the payload are
     * touched.
     */
    public function bulkEdit(Request $request): JsonResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;

        $v = $request->validate([
            'credential_ids'      => ['required', 'array', 'min:1', 'max:200'],
            'credential_ids.*'    => ['integer'],
            'verification_source' => ['nullable', Rule::in(array_keys(StaffCredential::VERIFICATION_SOURCES))],
            'cms_status'          => ['nullable', Rule::in(array_keys(StaffCredential::CMS_STATUSES))],
            'notes_append'        => ['nullable', 'string', 'max:1000'],
        ]);

        $rows = StaffCredential::forTenant($tenantId)
            ->whereIn('id', $v['credential_ids'])
            ->whereNull('replaced_by_credential_id')
            ->get();

        $touched = 0;
        foreach ($rows as $cred) {
            $patch = [];
            if (! empty($v['verification_source'])) $patch['verification_source'] = $v['verification_source'];
            if (! empty($v['cms_status']))          $patch['cms_status']          = $v['cms_status'];

            if (! empty($v['notes_append'])) {
                $stamp = '[' . now()->format('Y-m-d') . " {$request->user()->first_name}]: ";
                $patch['notes'] = trim(($cred->notes ? $cred->notes . "\n\n" : '') . $stamp . $v['notes_append']);
            }

            if (! empty($patch)) {
                $cred->update($patch);
                $touched++;
            }
        }

        AuditLog::record(
            action: 'staff_credential.bulk_edited',
            resourceType: 'StaffCredential',
            resourceId: 0,
            tenantId: $tenantId,
            userId: $request->user()->id,
            newValues: ['credential_count' => $touched, 'fields_touched' => array_keys($v)],
        );

        return response()->json(['ok' => true, 'updated_count' => $touched]);
    }

    /**
     * D5 : per-user printable credentials packet PDF for surveyor walk-ins.
     * Renders a clean Letter-sized PDF with the user's identity + every
     * non-superseded credential's status + expiry + doc-on-file flag.
     */
    public function exportPdf(Request $request, User $user)
    {
        $this->readGate($request);
        $this->assertSameTenant($user, $request);

        $credentials = StaffCredential::forTenant($user->tenant_id)
            ->where('user_id', $user->id)
            ->whereNull('replaced_by_credential_id')
            ->with('definition:id,is_cms_mandatory,requires_psv')
            ->orderBy('credential_type')
            ->orderBy('title')
            ->get();

        $defService = app(\App\Services\Credentials\CredentialDefinitionService::class);
        $missing = $defService->missingForUser($user);

        $pdf = Pdf::loadView('pdfs.credentials-packet', [
            'user'        => $user,
            'credentials' => $credentials,
            'missing'     => $missing,
            'generatedAt' => now(),
            'generatedBy' => $request->user(),
            'tenant'      => $user->tenant,
        ])->setPaper('letter', 'portrait');

        return $pdf->download("credentials-{$user->last_name}-{$user->first_name}-" . now()->format('Y-m-d') . ".pdf");
    }

    /**
     * D8 : one-click verify a pending credential. Used by IT Admin / QA when
     * a user has uploaded a self-attested renewal and the admin has reviewed
     * the document. Sets cms_status=active, verified_at=now, and bumps
     * verification_source from 'self_attestation' to 'uploaded_doc' (since the
     * admin has now visually verified the document).
     */
    public function verifyCredential(Request $request, StaffCredential $credential): JsonResponse
    {
        $this->gate($request);
        abort_if($credential->tenant_id !== $request->user()->tenant_id, 403);

        if ($credential->cms_status !== 'pending') {
            return response()->json([
                'message' => 'Only credentials in pending status can be one-click verified.',
            ], 422);
        }

        // Audit #2 finding A1 : if the def requires PSV, one-click verify cannot
        // satisfy it (uploaded_doc isn't state_board/npdb). The admin must use
        // the full edit modal to explicitly record state_board or npdb.
        $credential->loadMissing('definition');
        if ($credential->definition && $credential->definition->requires_psv) {
            $valid = in_array($credential->verification_source, ['state_board', 'npdb'], true);
            if (! $valid) {
                return response()->json([
                    'message' => 'This credential requires primary-source verification. Use the Edit modal to record verification_source as State Licensing Board or NPDB Lookup before activating.',
                ], 422);
            }
        }

        $old = $credential->toArray();
        $credential->update([
            'cms_status'          => 'active',
            'verified_at'         => now(),
            'verified_by_user_id' => $request->user()->id,
            // Promote from self_attestation to uploaded_doc since admin has
            // visually inspected the document. PSV-required defs already
            // satisfied above (state_board / npdb).
            'verification_source' => $credential->verification_source === 'self_attestation'
                ? 'uploaded_doc'
                : $credential->verification_source,
        ]);

        AuditLog::record(
            action: 'staff_credential.verified',
            resourceType: 'StaffCredential',
            resourceId: $credential->id,
            tenantId: $credential->tenant_id,
            userId: $request->user()->id,
            oldValues: $old,
            newValues: $credential->fresh()->toArray(),
        );

        return response()->json($credential->fresh());
    }

    public function destroyCredential(Request $request, StaffCredential $credential): JsonResponse
    {
        $this->gate($request);
        abort_if($credential->tenant_id !== $request->user()->tenant_id, 403);

        $old = $credential->toArray();
        $credential->delete();

        AuditLog::record(
            action: 'staff_credential.deleted',
            resourceType: 'StaffCredential',
            resourceId: $credential->id,
            tenantId: $credential->tenant_id,
            userId: $request->user()->id,
            oldValues: $old,
        );

        return response()->json(['ok' => true]);
    }

    public function storeTraining(Request $request, User $user): JsonResponse
    {
        $this->gate($request);
        $this->assertSameTenant($user, $request);

        $v = $request->validate([
            'training_name'  => ['required', 'string', 'max:200'],
            'category'       => ['required', Rule::in(StaffTrainingRecord::CATEGORIES)],
            'training_hours' => ['required', 'numeric', 'min:0', 'max:99'],
            'completed_at'   => ['required', 'date', 'before_or_equal:today'],
            // V2 : optionally count this training toward a credential's CEU renewal cycle.
            'credential_id'  => ['nullable', 'integer',
                Rule::exists('emr_staff_credentials', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', $user->tenant_id)->where('user_id', $user->id))],
            'notes'          => ['nullable', 'string', 'max:4000'],
        ]);

        $r = StaffTrainingRecord::create(array_merge($v, [
            'tenant_id' => $user->tenant_id,
            'user_id'   => $user->id,
            'verified_at'         => now(),
            'verified_by_user_id' => $request->user()->id,
        ]));

        AuditLog::record(
            action: 'staff_training.created',
            resourceType: 'StaffTrainingRecord',
            resourceId: $r->id,
            tenantId: $user->tenant_id,
            userId: $request->user()->id,
            newValues: $r->toArray(),
        );

        return response()->json($r, 201);
    }

    public function destroyTraining(Request $request, StaffTrainingRecord $record): JsonResponse
    {
        $this->gate($request);
        abort_if($record->tenant_id !== $request->user()->tenant_id, 403);

        $old = $record->toArray();
        $record->delete();

        AuditLog::record(
            action: 'staff_training.deleted',
            resourceType: 'StaffTrainingRecord',
            resourceId: $record->id,
            tenantId: $record->tenant_id,
            userId: $request->user()->id,
            oldValues: $old,
        );

        return response()->json(['ok' => true]);
    }
}
