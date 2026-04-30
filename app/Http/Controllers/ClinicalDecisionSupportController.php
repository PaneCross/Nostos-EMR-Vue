<?php

namespace App\Http\Controllers;

use App\Models\Participant;
use App\Services\ClinicalDecisionSupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ClinicalDecisionSupportController extends Controller
{
    public function __construct(private ClinicalDecisionSupportService $svc) {}

    public function evaluate(Participant $participant): JsonResponse
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        abort_unless($participant->tenant_id === $u->effectiveTenantId(), 404);
        abort_unless(
            $u->isSuperAdmin()
            || in_array($u->department, ['primary_care', 'pharmacy', 'therapies', 'it_admin']),
            403
        );
        return response()->json($this->svc->evaluate($participant));
    }
}
