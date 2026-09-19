<?php

namespace App\Policies;

use App\Models\Taqriz;
use App\Models\User;

/**
 * Authorship, not publication. Whether a taqriz goes live is decided by
 * TaqrizObserver, which is the only thing that can move `status` — a
 * reviewer passing every check here still cannot publish themselves.
 */
class TaqrizPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Taqriz $taqriz): bool
    {
        return $user->isAdmin() || $taqriz->taqrizchi?->user_id === $user->id;
    }

    /** A reviewer with a profile may write; an admin may write on their behalf. */
    public function create(User $user): bool
    {
        return $user->isAdmin() || ($user->isTaqrizchi() && $user->taqrizchi()->exists());
    }

    public function update(User $user, Taqriz $taqriz): bool
    {
        return $user->isAdmin() || $taqriz->taqrizchi?->user_id === $user->id;
    }

    public function delete(User $user, Taqriz $taqriz): bool
    {
        return $user->isAdmin() || $taqriz->taqrizchi?->user_id === $user->id;
    }

    public function restore(User $user, Taqriz $taqriz): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, Taqriz $taqriz): bool
    {
        return $user->isAdmin();
    }
}
