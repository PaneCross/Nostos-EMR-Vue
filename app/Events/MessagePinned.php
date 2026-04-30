<?php

// ─── MessagePinned + MessageUnpinned events ──────────────────────────────────
// Two events in one file for clarity ; both broadcast on the same channel
// and inform subscribers to refresh the pinned-message panel + flag the
// inline message as pinned / unpinned.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessagePinned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $channelId,
        public readonly int $messageId,
        public readonly int $pinnedByUserId,
        public readonly string $pinnedAtIso,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->channelId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.pinned';
    }

    public function broadcastWith(): array
    {
        return [
            'channel_id'         => $this->channelId,
            'message_id'         => $this->messageId,
            'pinned_by_user_id'  => $this->pinnedByUserId,
            'pinned_at'          => $this->pinnedAtIso,
        ];
    }
}
