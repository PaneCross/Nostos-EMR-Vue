<?php

// ─── TaskAssignedEvent : Phase M2 ────────────────────────────────────────────
// Broadcast when a StaffTask is created or re-assigned. Frontend task inboxes
// subscribe to tenant/department channels and live-update.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use App\Models\StaffTask;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskAssignedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly StaffTask $task) {}

    public function broadcastOn(): array
    {
        $channels = [new Channel("tenant.{$this->task->tenant_id}")];
        if ($this->task->assigned_to_department) {
            $channels[] = new Channel("department.{$this->task->tenant_id}.{$this->task->assigned_to_department}");
        }
        return $channels;
    }

    public function broadcastWith(): array
    {
        return [
            'id'            => $this->task->id,
            'title'         => $this->task->title,
            'priority'      => $this->task->priority,
            'status'        => $this->task->status,
            'assigned_to_user_id'   => $this->task->assigned_to_user_id,
            'assigned_to_department'=> $this->task->assigned_to_department,
            'due_at'        => $this->task->due_at?->toIso8601String(),
            'created_at'    => $this->task->created_at?->toIso8601String(),
        ];
    }
}
