<?php

namespace App\Policies;

use App\Models\ProfileSuggestion;
use App\Models\User;

/** Which facts reach an author's or translator's page is an admin's call. */
class ProfileSuggestionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, ProfileSuggestion $suggestion): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ProfileSuggestion $suggestion): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ProfileSuggestion $suggestion): bool
    {
        return $user->isAdmin();
    }
}
