<?php

namespace App\Policies;

use App\Models\SocialLink;
use App\Models\User;

class SocialLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SocialLink $socialLink): bool
    {
        return $user->isAdmin() || $socialLink->owner?->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SocialLink $socialLink): bool
    {
        return $user->isAdmin() || $socialLink->owner?->user_id === $user->id;
    }

    public function delete(User $user, SocialLink $socialLink): bool
    {
        return $user->isAdmin() || $socialLink->owner?->user_id === $user->id;
    }

    public function restore(User $user, SocialLink $socialLink): bool
    {
        return $user->isAdmin() || $socialLink->owner?->user_id === $user->id;
    }

    public function forceDelete(User $user, SocialLink $socialLink): bool
    {
        return $user->isAdmin();
    }
}
