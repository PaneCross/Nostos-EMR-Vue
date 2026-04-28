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
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class StaffCredentialController extends Controller
{
    /** Gate: IT admin, super admin, QA compliance (audit/review). */
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

    /** Scope: same tenant as the acting user. */
    private function assertSameTenant(User $staff, Request $request): void
    {
        abort_if($staff->tenant_id !== $request->user()->tenant_id, 403);
    }

    public function index(Request $request, User $user): InertiaResponse
    {
        $this->gate($request);
        $this->assertSameTenant($user, $request);

        $credentials = StaffCredential::forTenant($user->tenant_id)
            ->where('user_id', $user->id)
            ->orderByRaw('expires_at IS NULL ASC, expires_at ASC')
            ->get()
            ->map(fn (StaffCredential $c) => [
                'id'               => $c->id,
                'credential_type'  => $c->credential_type,
                'type_label'       => StaffCredential::TYPE_LABELS[$c->credential_type] ?? $c->credential_type,
                'title'            => $c->title,
                'license_state'    => $c->license_state,
                'license_number'   => $c->license_number,
                'issued_at'        => $c->issued_at?->toDateString(),
                'expires_at'       => $c->expires_at?->toDateString(),
                'days_remaining'   => $c->daysUntilExpiration(),
                'status'           => $c->status(),
                'verified_at'      => $c->verified_at?->toIso8601String(),
                'notes'            => $c->notes,
            ]);

        $training = StaffTrainingRecord::forTenant($user->tenant_id)
            ->where('user_id', $user->id)
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
            'notes'           => ['nullable', 'string', 'max:4000'],
            'document'        => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

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
            'notes'          => ['nullable', 'string', 'max:4000'],
            'document'       => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

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

    /** Save uploaded doc to local disk under tenant/{id}/credentials/{cred_id}.{ext}. */
    private function storeDocument(StaffCredential $credential, $file): void
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $dir = "credentials/tenant_{$credential->tenant_id}/user_{$credential->user_id}";
        $name = "cred_{$credential->id}_" . now()->format('YmdHis') . ".{$ext}";

        $path = $file->storeAs($dir, $name, 'local');

        $credential->update([
            'document_path'     => $path,
            'document_filename' => $file->getClientOriginalName(),
        ]);
    }

    public function destroyCredential(Request $request, StaffCredential $credential): JsonResponse
    {
        $this->gate($request);
        abort_if($credential->tenant_id !== $request->user()->tenant_id, 403);
        $credential->delete();
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
            'notes'          => ['nullable', 'string', 'max:4000'],
        ]);

        $r = StaffTrainingRecord::create(array_merge($v, [
            'tenant_id' => $user->tenant_id,
            'user_id'   => $user->id,
            'verified_at'         => now(),
            'verified_by_user_id' => $request->user()->id,
        ]));

        return response()->json($r, 201);
    }

    public function destroyTraining(Request $request, StaffTrainingRecord $record): JsonResponse
    {
        $this->gate($request);
        abort_if($record->tenant_id !== $request->user()->tenant_id, 403);
        $record->delete();
        return response()->json(['ok' => true]);
    }
}
