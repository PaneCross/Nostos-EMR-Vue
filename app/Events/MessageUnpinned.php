<?php

// ─── MessageUnpinned Event ───────────────────────────────────────────────────
// Broadcast when a pinned message is unpinned (or auto-cleared on delete).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageUnpinned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $channelId,
        public readonly int $messageId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->channelId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.unpinned';
    }

    public function broadcastWith(): array
    {
        return [
            'channel_id' => $this->channelId,
            'message_id' => $this->messageId,
        ];
    }
}
