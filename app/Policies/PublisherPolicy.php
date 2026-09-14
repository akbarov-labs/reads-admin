<?php

namespace App\Policies;

use App\Models\Publisher;
use App\Models\User;

/**
 * The publisher catalogue is shared between translators exactly as the
 * author catalogue is, and is guarded the same way — see AuthorPolicy for
 * the reasoning, including why create() stays open.
 */
class PublisherPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Publisher $publisher): bool
    {
        return $user->isAdmin();
    }

    /** @see \App\Policies\AuthorPolicy::create() */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Publisher $publisher): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Publisher $publisher): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Publisher $publisher): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, Publisher $publisher): bool
    {
        return $user->isAdmin();
    }
}
