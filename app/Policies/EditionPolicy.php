<?php

namespace App\Policies;

use App\Models\Edition;
use App\Models\User;

/**
 * An edition has two kinds of owner: the people credited on it (its
 * translators and editors) and the publisher that printed it. Either can
 * maintain it. That is what lets a publisher edit the editions they add,
 * which the old per-book check — translator only — never allowed.
 */
class EditionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Edition $edition): bool
    {
        return $this->owns($user, $edition);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Edition $edition): bool
    {
        return $this->owns($user, $edition);
    }

    public function delete(User $user, Edition $edition): bool
    {
        return $this->owns($user, $edition);
    }

    public function restore(User $user, Edition $edition): bool
    {
        return $this->owns($user, $edition);
    }

    public function forceDelete(User $user, Edition $edition): bool
    {
        return $user->isAdmin();
    }

    public function owns(User $user, Edition $edition): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        // Null-safe on both sides: an unclaimed publisher or a shell
        // translator has no user, and `null === $user->id` is false.
        if ($edition->publisher?->user_id === $user->id) {
            return true;
        }

        return $edition->translators()->where('translators.user_id', $user->id)->exists();
    }
}
