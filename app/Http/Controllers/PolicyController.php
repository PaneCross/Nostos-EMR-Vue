<?php

// ─── PolicyController ─────────────────────────────────────────────────────────
// Static policy pages required for modern PACE EMR posture:
//   - /policies/info-blocking  : 21st Century Cures Act / ONC HTI-1 policy
//   - /policies/npp            : HIPAA Notice of Privacy Practices
//   - /policies/acceptable-use : Staff acceptable-use of the EMR
//
// Pages are Inertia renders of static content in Policies/*.vue.
// Auth-required so only logged-in users see them (matches how NPP etc. is
// typically shared via patient portal; participant-facing policy distribution
// is out of scope for MVP).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PolicyController extends Controller
{
    public function infoBlocking(): InertiaResponse
    {
        return Inertia::render('Policies/InfoBlocking');
    }

    public function noticeOfPrivacyPractices(): InertiaResponse
    {
        return Inertia::render('Policies/NoticeOfPrivacyPractices');
    }

    public function acceptableUse(): InertiaResponse
    {
        return Inertia::render('Policies/AcceptableUse');
    }
}
