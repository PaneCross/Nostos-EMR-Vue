<?php

// ─── MessageEdited Event ─────────────────────────────────────────────────────
// Broadcast when a message is edited within the 5-min window.
// Subscribers replace the message text + display the edited indicator.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageEdited implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ChatMessage $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->message->channel_id}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.edited';
    }

    public function broadcastWith(): array
    {
        return [
            'message_id'   => $this->message->id,
            'message_text' => $this->message->message_text,
            'edited_at'    => $this->message->edited_at?->toIso8601String(),
        ];
    }
}
