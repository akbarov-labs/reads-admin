<?php

namespace App\Observers;

use App\Models\Publisher;
use App\Services\SiteRevalidator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * @see TranslatorObserver — same shape. A publisher may keep their own
 * details current but cannot move their public URL or reassign the account
 * the row belongs to.
 */
class PublisherObserver
{
    /** @var array<int, string> */
    private const ADMIN_ONLY = ['slug', 'user_id'];

    public function __construct(private readonly SiteRevalidator $revalidator) {}

    public function saving(Publisher $publisher): void
    {
        $user = Auth::user();

        // No authenticated user means a seeder, a console command or a test.
        if ($user === null || $user->isAdmin()) {
            return;
        }

        foreach (self::ADMIN_ONLY as $attribute) {
            if (! $publisher->isDirty($attribute)) {
                continue;
            }

            Log::warning('Blocked a non-admin write to an admin-only publisher field.', [
                'user_id' => $user->id,
                'publisher_id' => $publisher->id,
                'attribute' => $attribute,
            ]);

            $attributes = $publisher->getAttributes();
            $original = $publisher->getRawOriginal();

            if (array_key_exists($attribute, $original)) {
                $attributes[$attribute] = $original[$attribute];
            } else {
                unset($attributes[$attribute]);
            }

            $publisher->setRawAttributes($attributes);
        }
    }

    /**
     * The site's publisher pages are derived from the book catalogue rather
     * than fetched per slug, so there is no single tag to drop — the
     * collection ping covers them.
     */
    public function saved(Publisher $publisher): void
    {
        $this->revalidator->everything();
    }

    public function deleted(Publisher $publisher): void
    {
        $this->revalidator->everything();
    }
}
