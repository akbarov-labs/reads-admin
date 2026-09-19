<?php

namespace App\Observers;

use App\Models\Taqrizchi;
use App\Services\SiteRevalidator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * @see TranslatorObserver — same shape, same reasoning. A reviewer may edit
 * their own profile copy but not the fields that decide who they are or
 * where their page lives.
 */
class TaqrizchiObserver
{
    /** @var array<int, string> */
    private const ADMIN_ONLY = ['slug', 'user_id'];

    public function __construct(private readonly SiteRevalidator $revalidator) {}

    public function saving(Taqrizchi $taqrizchi): void
    {
        $user = Auth::user();

        // No authenticated user means a seeder, a console command or a test.
        if ($user === null || $user->isAdmin()) {
            return;
        }

        foreach (self::ADMIN_ONLY as $attribute) {
            if (! $taqrizchi->isDirty($attribute)) {
                continue;
            }

            Log::warning('Blocked a non-admin write to an admin-only taqrizchi field.', [
                'user_id' => $user->id,
                'taqrizchi_id' => $taqrizchi->id,
                'attribute' => $attribute,
            ]);

            $attributes = $taqrizchi->getAttributes();
            $original = $taqrizchi->getRawOriginal();

            if (array_key_exists($attribute, $original)) {
                $attributes[$attribute] = $original[$attribute];
            } else {
                unset($attributes[$attribute]);
            }

            $taqrizchi->setRawAttributes($attributes);
        }
    }

    public function saved(Taqrizchi $taqrizchi): void
    {
        $this->revalidator->taqrizchi($taqrizchi->slug);

        $previousSlug = $taqrizchi->getOriginal('slug');

        if ($previousSlug && $previousSlug !== $taqrizchi->slug) {
            $this->revalidator->taqrizchi($previousSlug);
        }
    }

    public function deleted(Taqrizchi $taqrizchi): void
    {
        $this->revalidator->taqrizchi($taqrizchi->slug);
    }
}
