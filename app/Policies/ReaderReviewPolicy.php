<?php

namespace App\Policies;

use App\Models\ReaderReview;
use App\Models\User;

/** Readers' reviews are moderated by admins only. */
class ReaderReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, ReaderReview $review): bool
    {
        return $user->isAdmin();
    }

    /** They only ever arrive from the bot. */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ReaderReview $review): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ReaderReview $review): bool
    {
        return $user->isAdmin();
    }
}
