<?php

// ─── JobTitleController ──────────────────────────────────────────────────────
// Executive-gated CRUD for the org's job-title vocabulary. Lives behind
// /executive/job-titles. Used to populate dropdowns when creating staff users
// and when targeting credential definitions.
//
// Soft-delete (no hard delete) preserves historical user.job_title strings.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\JobTitle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class JobTitleController extends Controller
{
    /** Inertia page : the actual list-management UI. */
    public function page(Request $request): InertiaResponse
    {
        $this->gate($request);
        return Inertia::render('Executive/JobTitles');
    }

    private function gate(Request $request): void
    {
        $u = $request->user();
        abort_unless($u, 401);
        abort_unless(
            $u->isSuperAdmin() || $u->department === 'executive',
            403,
            'Only Executive (or Super Admin) may manage job titles.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->gate($request);

        $titles = JobTitle::forTenant($request->user()->tenant_id)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();

        return response()->json($titles);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;

        $v = $request->validate([
            'code'       => [
                'required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('emr_job_titles', 'code')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'label'      => ['required', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active'  => ['nullable', 'boolean'],
        ]);

        $jt = JobTitle::create(array_merge($v, [
            'tenant_id'  => $tenantId,
            'is_active'  => $v['is_active'] ?? true,
            'sort_order' => $v['sort_order'] ?? 100,
        ]));

        AuditLog::record(
            action: 'job_title.created',
            resourceType: 'JobTitle',
            resourceId: $jt->id,
            tenantId: $tenantId,
            userId: $request->user()->id,
            newValues: $jt->toArray(),
        );

        return response()->json($jt, 201);
    }

    public function update(Request $request, JobTitle $jobTitle): JsonResponse
    {
        $this->gate($request);
        abort_if($jobTitle->tenant_id !== $request->user()->tenant_id, 403);

        $v = $request->validate([
            'label'      => ['sometimes', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active'  => ['nullable', 'boolean'],
        ]);

        $old = $jobTitle->toArray();
        $jobTitle->update($v);

        AuditLog::record(
            action: 'job_title.updated',
            resourceType: 'JobTitle',
            resourceId: $jobTitle->id,
            tenantId: $request->user()->tenant_id,
            userId: $request->user()->id,
            oldValues: $old,
            newValues: $jobTitle->fresh()->toArray(),
        );

        return response()->json($jobTitle->fresh());
    }

    public function destroy(Request $request, JobTitle $jobTitle): JsonResponse
    {
        $this->gate($request);
        abort_if($jobTitle->tenant_id !== $request->user()->tenant_id, 403);

        // E4 : null out users.job_title for any user pointing at this code so
        // their credential targeting doesn't silently break. The deactivation
        // returns the count so the UI can flag re-assignment as a follow-up.
        $affectedUsers = \App\Models\User::where('tenant_id', $jobTitle->tenant_id)
            ->where('job_title', $jobTitle->code)
            ->pluck('id')->all();
        if (! empty($affectedUsers)) {
            \App\Models\User::whereIn('id', $affectedUsers)->update(['job_title' => null]);
        }

        $jobTitle->delete();   // soft delete

        AuditLog::record(
            action: 'job_title.deactivated',
            resourceType: 'JobTitle',
            resourceId: $jobTitle->id,
            tenantId: $request->user()->tenant_id,
            userId: $request->user()->id,
            newValues: ['affected_user_ids' => $affectedUsers],
        );

        return response()->json([
            'ok' => true,
            'affected_users' => count($affectedUsers),
            'message' => count($affectedUsers) > 0
                ? "Deactivated. " . count($affectedUsers) . " user(s) had this title : their job_title is now null and they need to be reassigned for credential targeting to work."
                : 'Deactivated.',
        ]);
    }
}
