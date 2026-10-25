<?php

namespace App\Observers;

use App\Models\Library;
use App\Services\SiteRevalidator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * @see PublisherObserver — same shape. An owner keeps their library's page
 * current but cannot move its URL, hand it to another account, or put it on
 * the site: publishing is the admin's call once the library is set up.
 */
class LibraryObserver
{
    /** @var array<int, string> */
    private const ADMIN_ONLY = ['slug', 'user_id', 'is_published'];

    public function __construct(private readonly SiteRevalidator $revalidator) {}

    public function saving(Library $library): void
    {
        $user = Auth::user();

        // No authenticated user means a seeder, a console command or a test.
        if ($user === null || $user->isAdmin() || ! $library->exists) {
            return;
        }

        foreach (self::ADMIN_ONLY as $attribute) {
            if (! $library->isDirty($attribute)) {
                continue;
            }

            Log::warning('Blocked a non-admin write to an admin-only library field.', [
                'user_id' => $user->id,
                'library_id' => $library->id,
                'attribute' => $attribute,
            ]);

            // A column the row was loaded without (a default the database
            // filled in) is left out of the update rather than set to null.
            $attributes = $library->getAttributes();
            $original = $library->getRawOriginal();

            if (array_key_exists($attribute, $original)) {
                $attributes[$attribute] = $original[$attribute];
            } else {
                unset($attributes[$attribute]);
            }

            $library->setRawAttributes($attributes);
        }
    }

    public function saved(Library $library): void
    {
        $this->revalidator->library($library->slug);
    }

    public function deleted(Library $library): void
    {
        $this->revalidator->everything();
    }
}
