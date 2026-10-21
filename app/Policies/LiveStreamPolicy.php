<?php

namespace App\Policies;

use App\Enums\LiveStatus;
use App\Enums\Permission;
use App\Models\LiveStream;
use App\Models\User;

/**
 * Starting a public broadcast is an editorial call: only reviewers (editor/admin) may see or run the Live screens,
 * journalists never. WHO may act is decided here; WHICH moves are legal (scheduled or ended to live, live to ended)
 * is decided by LiveStream::goLive()/end(). A live broadcast cannot be deleted: end it first. No bulk deletion.
 */
class LiveStreamPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_active ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageBroadcasts);
    }

    public function view(User $user, LiveStream $liveStream): bool
    {
        return $user->hasPermission(Permission::ManageBroadcasts);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageBroadcasts);
    }

    public function update(User $user, LiveStream $liveStream): bool
    {
        return $user->hasPermission(Permission::ManageBroadcasts);
    }

    public function goLive(User $user, LiveStream $liveStream): bool
    {
        return $user->hasPermission(Permission::ManageBroadcasts);
    }

    public function end(User $user, LiveStream $liveStream): bool
    {
        return $user->hasPermission(Permission::ManageBroadcasts);
    }

    public function delete(User $user, LiveStream $liveStream): bool
    {
        return $user->hasPermission(Permission::ManageBroadcasts) && $liveStream->status !== LiveStatus::Live;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
