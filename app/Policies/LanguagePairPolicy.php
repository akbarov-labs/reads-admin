<?php

namespace App\Policies;

use App\Models\LanguagePair;
use App\Models\User;

class LanguagePairPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LanguagePair $languagePair): bool
    {
        return $user->isAdmin() || $languagePair->translator->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, LanguagePair $languagePair): bool
    {
        return $user->isAdmin() || $languagePair->translator->user_id === $user->id;
    }

    public function delete(User $user, LanguagePair $languagePair): bool
    {
        return $user->isAdmin() || $languagePair->translator->user_id === $user->id;
    }

    public function restore(User $user, LanguagePair $languagePair): bool
    {
        return $user->isAdmin() || $languagePair->translator->user_id === $user->id;
    }

    public function forceDelete(User $user, LanguagePair $languagePair): bool
    {
        return $user->isAdmin();
    }
}
