<?php

// ─── ChannelMembersChanged Event ─────────────────────────────────────────────
// Broadcast when members are added or removed from a channel — manually (group
// DM management) or automatically (role-group sync). Subscribers refresh the
// channel's member list + show a system message in the thread.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChannelMembersChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  int[]  $addedUserIds
     * @param  int[]  $removedUserIds
     */
    public function __construct(
        public readonly int $channelId,
        public readonly array $addedUserIds = [],
        public readonly array $removedUserIds = [],
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->channelId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.channel.members_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'channel_id' => $this->channelId,
            'added'      => $this->addedUserIds,
            'removed'    => $this->removedUserIds,
        ];
    }
}
