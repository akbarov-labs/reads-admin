<?php

namespace App\Policies;

use App\Models\Book;
use App\Models\User;

/**
 * The book is the work, shared by every edition of it: its title, authors
 * and genres show on every translator's and publisher's page that has an
 * edition of it. So a non-admin may change it only while every edition is
 * theirs. Once somebody else's edition exists, renaming the work would
 * rename their book too, and that takes an admin.
 *
 * A book with no editions yet — one a taqrizchi or a publisher is in the
 * middle of adding — is editable by nobody but an admin, which is fine:
 * the forms that create one also create its first edition in one go.
 */
class BookPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Book $book): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Book $book): bool
    {
        return $user->isAdmin() || $this->ownsEveryEdition($user, $book);
    }

    public function delete(User $user, Book $book): bool
    {
        return $user->isAdmin() || $this->ownsEveryEdition($user, $book);
    }

    public function restore(User $user, Book $book): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, Book $book): bool
    {
        return $user->isAdmin();
    }

    private function ownsEveryEdition(User $user, Book $book): bool
    {
        $editions = $book->editions()->with(['publisher', 'translators'])->get();
        $editionPolicy = new EditionPolicy;

        return $editions->isNotEmpty() && $editions->every(fn ($edition) => $editionPolicy->owns($user, $edition));
    }
}
