<?php

namespace App\Policies;

use App\Models\Author;
use App\Models\User;

/**
 * Authors are a shared catalogue: one row is referenced by the books of
 * every translator who has translated that writer. Renaming "Ernest
 * Hemingway" rewrites the credit under someone else's book, and deleting the
 * row nulls out author_id on all of them — so editing the catalogue is an
 * admin job, which is what AuthorResource::shouldRegisterNavigation() has
 * always said.
 *
 * Without this class Filament fell back to "no policy, allow everything":
 * hiding the sidebar link is not an access control, and /admin/authors
 * answered 200 for any signed-in translator.
 *
 * Creating is the deliberate exception — see create().
 */
class AuthorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Author $author): bool
    {
        return $user->isAdmin();
    }

    /**
     * Open to translators as well, because the author picker on the book
     * form creates rows inline (CatalogueFields::authorsSelect's `createOptionForm`):
     * a translator logging a book by a writer nobody has entered yet has to
     * be able to add them, or they cannot record the book at all. Adding a
     * new row cannot damage anyone else's data — only editing an existing
     * one can, and that is admin-only above.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Author $author): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Author $author): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Author $author): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, Author $author): bool
    {
        return $user->isAdmin();
    }
}
