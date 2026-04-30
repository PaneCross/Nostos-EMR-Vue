<?php

// ─── UserJobTitleObserver ────────────────────────────────────────────────────
// Auto-add hook for Chat v2 role-group channels.
//
// Watches the User model. When a user is created or updated, if their
// job_title or department changed (or this is a new user), kick off
// ChatService::syncRoleGroupMemberships() to add them to any role-group
// channel they now qualify for and remove them from any they no longer do.
//
// Audit log writes happen inside the service (one row per add and one per
// remove). The observer itself is silent ; it just dispatches.
//
// Wiring : registered in AppServiceProvider::boot() via User::observe().
//
// See docs/plans/chat_v2_plan.md §5.2.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Observers;

use App\Models\User;
use App\Services\ChatService;

class UserJobTitleObserver
{
    public function __construct(private readonly ChatService $chatService) {}

    /** New user : run a fresh sync (will add them to anything they qualify for). */
    public function created(User $user): void
    {
        $this->chatService->syncRoleGroupMemberships($user);
    }

    /**
     * On update, only sync if a relevant field changed. Avoids dispatching
     * a sync on every theme_preference change, etc.
     */
    public function updated(User $user): void
    {
        $relevantFields = ['job_title', 'department', 'is_active'];
        $dirty = array_intersect($relevantFields, array_keys($user->getChanges()));
        if (empty($dirty)) {
            return;
        }
        $this->chatService->syncRoleGroupMemberships($user);
    }
}
