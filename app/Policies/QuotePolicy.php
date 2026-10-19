<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;

/** Quotes are the catalogue's own editorial choice: admins only. */
class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Quote $quote): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Quote $quote): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Quote $quote): bool
    {
        return $user->isAdmin();
    }
}
