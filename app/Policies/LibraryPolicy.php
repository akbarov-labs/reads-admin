<?php

namespace App\Policies;

use App\Models\Library;
use App\Models\User;

/**
 * An admin adds libraries; an owner signs in to keep their own one. Unlike
 * publishers, nobody else creates one inline: a library is always one we
 * have spoken to.
 */
class LibraryPolicy
{
    /** The query is scoped separately, so an owner's list is their one row. */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isLibrary();
    }

    public function view(User $user, Library $library): bool
    {
        return $user->isAdmin() || $this->owns($user, $library);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Library $library): bool
    {
        return $user->isAdmin() || $this->owns($user, $library);
    }

    public function delete(User $user, Library $library): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }

    private function owns(User $user, Library $library): bool
    {
        return $library->user_id !== null && $library->user_id === $user->id;
    }
}
