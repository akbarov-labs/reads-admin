<?php

namespace App\Policies;

use App\Models\PublisherSuggestion;
use App\Models\User;

/** Which details reach a publisher's page is an admin's call. */
class PublisherSuggestionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, PublisherSuggestion $suggestion): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PublisherSuggestion $suggestion): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, PublisherSuggestion $suggestion): bool
    {
        return $user->isAdmin();
    }
}
