<?php

// ─── NoteTemplateController ──────────────────────────────────────────────────
// Phase B7. Library for clinical note templates. System templates (tenant_id
// NULL) are read-only to all tenants; tenant-local templates are managed
// by qa_compliance + super_admin.
//
// Routes:
//   GET    /note-templates                        index()
//   POST   /note-templates                        store()
//   PUT    /note-templates/{template}             update()
//   DELETE /note-templates/{template}             destroy()
//   GET    /note-templates/{template}/render/{p}  render()  — returns filled body
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ClinicalNote;
use App\Models\NoteTemplate;
use App\Models\Participant;
use App\Services\NoteTemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NoteTemplateController extends Controller
{
    private function gateRead(): void
    {
        abort_unless(Auth::check(), 401);
    }

    private function gateWrite(): void
    {
        $u = Auth::user();
        abort_unless($u, 401);
        abort_unless(
            $u->isSuperAdmin() || in_array($u->department, ['qa_compliance'], true),
            403,
            'Only QA compliance + super admin may manage note templates.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->gateRead();
        $u = Auth::user();
        $templates = NoteTemplate::availableTo($u->tenant_id)
            ->orderBy('note_type')->orderBy('name')->get();
        return response()->json(['templates' => $templates]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gateWrite();
        $u = Auth::user();
        $validated = $request->validate([
            'name'          => 'required|string|max:120',
            'note_type'     => 'required|string|in:' . implode(',', ClinicalNote::NOTE_TYPES),
            'department'    => 'nullable|string|max:40',
            'body_markdown' => 'required|string|max:20000',
        ]);
        $template = NoteTemplate::create(array_merge($validated, [
            'tenant_id'          => $u->tenant_id,
            'is_system'          => false,
            'created_by_user_id' => $u->id,
        ]));
        AuditLog::record(
            action: 'note_template.created',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'note_template',
            resourceId: $template->id,
            description: "Note template '{$template->name}' created.",
        );
        return response()->json(['template' => $template], 201);
    }

    public function update(Request $request, NoteTemplate $template): JsonResponse
    {
        $this->gateWrite();
        $u = Auth::user();
        abort_if($template->is_system, 403, 'System templates are read-only.');
        abort_if($template->tenant_id !== $u->tenant_id, 403);

        $validated = $request->validate([
            'name'          => 'sometimes|string|max:120',
            'note_type'     => 'sometimes|string|in:' . implode(',', ClinicalNote::NOTE_TYPES),
            'department'    => 'nullable|string|max:40',
            'body_markdown' => 'sometimes|string|max:20000',
        ]);
        $template->update($validated);
        return response()->json(['template' => $template->fresh()]);
    }

    public function destroy(NoteTemplate $template): JsonResponse
    {
        $this->gateWrite();
        $u = Auth::user();
        abort_if($template->is_system, 403, 'System templates cannot be deleted.');
        abort_if($template->tenant_id !== $u->tenant_id, 403);
        $template->delete();
        return response()->json(null, 204);
    }

    public function render(Request $request, NoteTemplate $template, Participant $participant, NoteTemplateRenderer $renderer): JsonResponse
    {
        $this->gateRead();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->tenant_id, 403);
        // Template must be system OR same-tenant.
        abort_if(! $template->is_system && $template->tenant_id !== $u->tenant_id, 403);

        $rendered = $renderer->render($template, $participant, $u);
        return response()->json([
            'template_id'  => $template->id,
            'name'         => $template->name,
            'note_type'    => $template->note_type,
            'rendered'     => $rendered,
        ]);
    }
}
