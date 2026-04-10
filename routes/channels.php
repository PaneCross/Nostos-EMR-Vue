<?php

use App\Models\ChatMembership;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// ─── Tenant-wide channel ──────────────────────────────────────────────────────
// Used by AlertCreated events pushed to all users in a tenant.
Broadcast::channel('tenant.{tenantId}', function ($user, $tenantId) {
    return (int) $user->tenant_id === (int) $tenantId;
});

// ─── Chat Channels ────────────────────────────────────────────────────────────
// Private channel per chat channel ID. Authorizes based on ChatMembership.
// Only users who are members of the channel may subscribe.
Broadcast::channel('chat.{channelId}', function ($user, $channelId) {
    return ChatMembership::where('channel_id', (int) $channelId)
        ->where('user_id', $user->id)
        ->exists();
});

// ─── Personal User Channel ────────────────────────────────────────────────────
// Used by ChatActivityEvent to push "you have new chat activity" notifications
// to a user's nav badge without subscribing to all their chat channels.
// Authorized only for the owning user.
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
