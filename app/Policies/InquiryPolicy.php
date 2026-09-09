<?php

namespace App\Policies;

use App\Models\Inquiry;
use App\Models\User;

class InquiryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Inquiry $inquiry): bool
    {
        return $user->isAdmin() || $inquiry->translator->user_id === $user->id;
    }

    /**
     * Inquiries only ever come in through the public API — nobody creates
     * one from the admin panel.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Inquiry $inquiry): bool
    {
        return $user->isAdmin() || $inquiry->translator->user_id === $user->id;
    }

    public function delete(User $user, Inquiry $inquiry): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Inquiry $inquiry): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, Inquiry $inquiry): bool
    {
        return $user->isAdmin();
    }
}
