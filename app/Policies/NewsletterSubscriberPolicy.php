<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\NewsletterSubscriber;
use App\Models\User;

/** Subscriber addresses are personal data: editors and admins read them, only admins delete. */
class NewsletterSubscriberPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_active ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewSubscribers);
    }

    public function view(User $user, NewsletterSubscriber $subscriber): bool
    {
        return $user->hasPermission(Permission::ViewSubscribers);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, NewsletterSubscriber $subscriber): bool
    {
        return false;
    }

    public function delete(User $user, NewsletterSubscriber $subscriber): bool
    {
        return $user->hasPermission(Permission::DeleteSubscribers);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission(Permission::DeleteSubscribers);
    }
}
