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
    /**
     * A publisher maintains their own catalogue entry, so the resource is no
     * longer admin-only. The Eloquent query is scoped separately, so a
     * publisher only ever sees their own row in the list.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isPublisher();
    }

    public function view(User $user, Publisher $publisher): bool
    {
        return $user->isAdmin() || $this->owns($user, $publisher);
    }

    /** @see \App\Policies\AuthorPolicy::create() */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Publisher $publisher): bool
    {
        return $user->isAdmin() || $this->owns($user, $publisher);
    }

    /**
     * An unclaimed publisher — one backfilled from the old free-text column
     * with no login attached — is owned by nobody, so `null === $user->id`
     * correctly refuses everyone but an admin.
     */
    private function owns(User $user, Publisher $publisher): bool
    {
        return $publisher->user_id !== null && $publisher->user_id === $user->id;
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
