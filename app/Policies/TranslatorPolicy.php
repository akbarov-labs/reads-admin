<?php

namespace App\Policies;

use App\Models\Translator;
use App\Models\User;

class TranslatorPolicy
{
    /**
     * Everyone with panel access may open the resource — the resource's
     * Eloquent query is scoped separately so a translator only ever sees
     * their own record in the list.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Translator $translator): bool
    {
        return $user->isAdmin() || $translator->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Translator $translator): bool
    {
        return $user->isAdmin() || $translator->user_id === $user->id;
    }

    public function delete(User $user, Translator $translator): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Translator $translator): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, Translator $translator): bool
    {
        return $user->isAdmin();
    }
}
