<?php

namespace App\Policies;

use App\Models\Collection;
use App\Models\User;

/** Collections are the catalogue's own editorial choice: admins only. */
class CollectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Collection $collection): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Collection $collection): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Collection $collection): bool
    {
        return $user->isAdmin();
    }
}
