<?php

namespace App\Observers;

use App\Models\Translator;
use App\Services\SiteRevalidator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TranslatorObserver
{
    /**
     * Columns only an admin may write. Hiding them in the panel is a UI
     * courtesy; this is the actual control. A translator who replays a
     * crafted Livewire request, or any future code path that fills a model
     * straight from request data, still cannot move them.
     *
     * @var array<int, string>
     */
    private const ADMIN_ONLY = [
        'meta_title',
        'meta_description',
        'og_image_path',
        'seo_noindex',
        'slug',
        'user_id',
    ];

    public function __construct(private readonly SiteRevalidator $revalidator) {}

    public function saving(Translator $translator): void
    {
        $user = Auth::user();

        // No authenticated user means this is a seeder, a console command or
        // a test — trusted contexts that legitimately set these.
        if ($user === null || $user->isAdmin()) {
            return;
        }

        foreach (self::ADMIN_ONLY as $attribute) {
            if (! $translator->isDirty($attribute)) {
                continue;
            }

            Log::warning('Blocked a non-admin write to an admin-only translator field.', [
                'user_id' => $user->id,
                'translator_id' => $translator->id,
                'attribute' => $attribute,
            ]);

            // Put the stored value back rather than throwing: the rest of
            // the save (bio, avatar, books) is legitimate and should go
            // through.
            //
            // When the model was never loaded with this column, there is no
            // original to restore — dropping the key entirely leaves the
            // column out of the UPDATE, which is what "leave it alone" has
            // to mean. Writing getRawOriginal()'s null instead would clear a
            // NOT NULL column and fail the whole save.
            $attributes = $translator->getAttributes();
            $original = $translator->getRawOriginal();

            if (array_key_exists($attribute, $original)) {
                $attributes[$attribute] = $original[$attribute];
            } else {
                unset($attributes[$attribute]);
            }

            $translator->setRawAttributes($attributes);
        }
    }

    public function saved(Translator $translator): void
    {
        $this->revalidator->translator($translator->slug);

        // A renamed slug changes the URL: the old path has to be dropped
        // from the cache too, or it lingers as a stale duplicate page.
        $previousSlug = $translator->getOriginal('slug');

        if ($previousSlug && $previousSlug !== $translator->slug) {
            $this->revalidator->translator($previousSlug);
        }
    }

    public function deleted(Translator $translator): void
    {
        $this->revalidator->translator($translator->slug);
    }
}
