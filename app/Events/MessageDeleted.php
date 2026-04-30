<?php

// ─── MessageDeleted Event ────────────────────────────────────────────────────
// Broadcast when a message is soft-deleted. Subscribers replace the message
// content with the "deleted" placeholder. The original text is NOT broadcast
// (preserved server-side only, audit trail).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $channelId,
        public readonly int $messageId,
        public readonly int $deletedByUserId,
        public readonly string $deletedAtIso,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->channelId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.deleted';
    }

    public function broadcastWith(): array
    {
        return [
            'message_id'         => $this->messageId,
            'deleted_by_user_id' => $this->deletedByUserId,
            'deleted_at'         => $this->deletedAtIso,
        ];
    }
}
