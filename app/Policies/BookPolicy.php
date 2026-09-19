<?php

namespace App\Policies;

use App\Models\Book;
use App\Models\User;

/**
 * A book may have no translator at all now, so every ownership check is
 * null-safe: `null === $user->id` is false, which correctly means "nobody
 * owns this but an admin". Without the null-safe operator these threw.
 */
class BookPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Book $book): bool
    {
        return $user->isAdmin() || $book->translator?->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Book $book): bool
    {
        return $user->isAdmin() || $book->translator?->user_id === $user->id;
    }

    public function delete(User $user, Book $book): bool
    {
        return $user->isAdmin() || $book->translator?->user_id === $user->id;
    }

    public function restore(User $user, Book $book): bool
    {
        return $user->isAdmin() || $book->translator?->user_id === $user->id;
    }

    public function forceDelete(User $user, Book $book): bool
    {
        return $user->isAdmin();
    }
}
