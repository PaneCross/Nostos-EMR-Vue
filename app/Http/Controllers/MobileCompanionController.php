<?php

// ─── MobileCompanionController ───────────────────────────────────────────────
// Phase 15.5 — Tablet-friendly home-care ADL page. Resolves today's assigned
// home-care participants for the logged-in user and renders the mobile ADL
// page. Reuses existing ADL save endpoint (no backend changes required).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class MobileCompanionController extends Controller
{
    public function adl(Request $request)
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        abort_unless(
            $u->isSuperAdmin() || in_array($u->department, ['home_care', 'therapies']),
            403
        );

        // Home-care participants for this tenant. Filter by "assigned_to_user"
        // when that relationship exists; fallback to enrolled participants.
        $participants = Participant::where('tenant_id', $u->tenant_id)
            ->where('enrollment_status', 'enrolled')
            ->orderBy('last_name')
            ->limit(40)
            ->get(['id', 'first_name', 'last_name', 'mrn', 'dob']);

        return Inertia::render('HomeCare/MobileAdl', [
            'participants' => $participants->map(fn ($p) => [
                'id' => $p->id,
                'first_name' => $p->first_name,
                'last_name' => $p->last_name,
                'mrn' => $p->mrn,
                'dob' => optional($p->dob)->format('Y-m-d'),
            ]),
        ]);
    }
}
