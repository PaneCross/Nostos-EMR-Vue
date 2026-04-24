<?php

// ─── MobileHomeVisitsController — Phase M5 ──────────────────────────────────
namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class MobileHomeVisitsController extends Controller
{
    public function index(Request $request)
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = ['home_care', 'primary_care', 'therapies', 'it_admin'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);

        return Inertia::render('Mobile/Index', [
            'today' => $this->todayVisits($u),
        ]);
    }

    public function todayJson(Request $request)
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        return response()->json(['visits' => $this->todayVisits($u)]);
    }

    private function todayVisits($u): array
    {
        return Appointment::where('tenant_id', $u->tenant_id)
            ->whereDate('scheduled_start', now()->toDateString())
            ->where(function ($q) use ($u) {
                $q->where('provider_user_id', $u->id)
                  ->orWhereNull('provider_user_id');
            })
            ->with('participant:id,first_name,last_name,mrn')
            ->orderBy('scheduled_start')
            ->limit(40)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'scheduled_start' => $a->scheduled_start?->toIso8601String(),
                'status' => $a->status,
                'appointment_type' => $a->appointment_type,
                'participant' => $a->participant ? [
                    'id' => $a->participant->id,
                    'name' => $a->participant->first_name . ' ' . $a->participant->last_name,
                    'mrn' => $a->participant->mrn,
                ] : null,
            ])->all();
    }
}
