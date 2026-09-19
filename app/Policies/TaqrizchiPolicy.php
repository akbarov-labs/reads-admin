<?php

namespace App\Policies;

use App\Models\Taqrizchi;
use App\Models\User;

/**
 * @see TranslatorPolicy — same rules. The resource's Eloquent query is scoped
 * separately, so a taqrizchi only ever sees their own record in the list.
 */
class TaqrizchiPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Taqrizchi $taqrizchi): bool
    {
        return $user->isAdmin() || $taqrizchi->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Taqrizchi $taqrizchi): bool
    {
        return $user->isAdmin() || $taqrizchi->user_id === $user->id;
    }

    public function delete(User $user, Taqrizchi $taqrizchi): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Taqrizchi $taqrizchi): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, Taqrizchi $taqrizchi): bool
    {
        return $user->isAdmin();
    }
}
