<?php

// ─── MessageReacted Event ────────────────────────────────────────────────────
// Broadcast on private-chat.{channelId} when a user adds or removes a
// reaction. Frontend updates the per-message reaction counters live.
// See docs/plans/chat_v2_plan.md §7.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReacted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $channelId,
        public readonly int $messageId,
        public readonly int $userId,
        public readonly string $reaction,
        public readonly string $action, // 'added' | 'removed'
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->channelId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.reacted';
    }

    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'user_id'    => $this->userId,
            'reaction'   => $this->reaction,
            'action'     => $this->action,
        ];
    }
}
