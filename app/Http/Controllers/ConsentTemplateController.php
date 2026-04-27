<?php

// ─── ConsentTemplateController ───────────────────────────────────────────────
// Phase G4. Versioned consent templates; QA approval workflow; reprompt
// detector (participants with an acknowledged ConsentRecord of type X but not
// against the latest approved template).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ConsentRecord;
use App\Models\ConsentTemplate;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ConsentTemplateController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
    }

    private function gateWrite(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        abort_unless($u->isSuperAdmin() || in_array($u->department, ['qa_compliance', 'enrollment'], true), 403);
    }

    public function index(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        return response()->json([
            'templates' => ConsentTemplate::forTenant($u->tenant_id)
                ->orderBy('consent_type')->orderByDesc('created_at')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gateWrite();
        $u = Auth::user();
        $validated = $request->validate([
            'consent_type' => 'required|in:' . implode(',', ConsentRecord::CONSENT_TYPES),
            'version'      => 'required|string|max:30',
            'title'        => 'required|string|max:200',
            'body'         => 'required|string|max:50000',
        ]);
        $template = ConsentTemplate::create(array_merge($validated, [
            'tenant_id' => $u->tenant_id,
            'status'    => 'draft',
        ]));
        AuditLog::record(
            action: 'consent_template.created',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'consent_template',
            resourceId: $template->id,
            description: "Consent template {$template->consent_type} v{$template->version} created in draft.",
        );
        return response()->json(['template' => $template], 201);
    }

    public function approve(Request $request, ConsentTemplate $template): JsonResponse
    {
        $this->gateWrite();
        $u = Auth::user();
        abort_if($template->tenant_id !== $u->tenant_id, 403);
        if ($template->status !== 'draft') {
            return response()->json(['error' => 'invalid_state'], 409);
        }

        // Archive prior approved version of the same (tenant, consent_type).
        ConsentTemplate::forTenant($u->tenant_id)
            ->where('consent_type', $template->consent_type)
            ->where('status', 'approved')
            ->update(['status' => 'archived']);

        $template->update([
            'status' => 'approved',
            'approved_by_user_id' => $u->id,
            'approved_at' => now(),
        ]);

        AuditLog::record(
            action: 'consent_template.approved',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'consent_template',
            resourceId: $template->id,
            description: "Consent template {$template->consent_type} v{$template->version} approved; prior approved archived.",
        );

        return response()->json(['template' => $template->fresh()]);
    }

    /**
     * GET /consent-templates/reprompt-queue : participants whose most recent
     * acknowledged ConsentRecord for any type is on an older template than the
     * currently-approved one.
     */
    public function repromptQueue(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();

        $approvedByType = ConsentTemplate::forTenant($u->tenant_id)
            ->approved()->get()->keyBy('consent_type');

        $queue = [];
        foreach ($approvedByType as $type => $template) {
            $stale = ConsentRecord::forTenant($u->tenant_id)
                ->where('consent_type', $type)
                ->where('status', 'acknowledged')
                ->where(function ($q) use ($template) {
                    $q->whereNull('consent_template_id')
                      ->orWhere('consent_template_id', '!=', $template->id);
                })
                ->with('participant:id,mrn,first_name,last_name')
                ->get();
            foreach ($stale as $rec) {
                $queue[] = [
                    'consent_record_id'       => $rec->id,
                    'consent_type'            => $type,
                    'current_template_id'     => $template->id,
                    'current_template_version'=> $template->version,
                    'participant'             => $rec->participant ? [
                        'id'   => $rec->participant->id,
                        'mrn'  => $rec->participant->mrn,
                        'name' => $rec->participant->first_name . ' ' . $rec->participant->last_name,
                    ] : null,
                ];
            }
        }

        return response()->json(['queue' => $queue, 'count' => count($queue)]);
    }
}
