<?php

// ─── MessageRead Event ───────────────────────────────────────────────────────
// Broadcast on first-read so the receipt count on each message updates live.
// See docs/plans/chat_v2_plan.md §7.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $channelId,
        public readonly int $messageId,
        public readonly int $userId,
        public readonly string $readAtIso,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->channelId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.read';
    }

    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'user_id'    => $this->userId,
            'read_at'    => $this->readAtIso,
        ];
    }
}
