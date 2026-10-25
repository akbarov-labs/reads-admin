<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Edition;
use App\Models\Publisher;
use App\Models\PublisherAlias;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Folds one publisher into another when the catalogue holds the same one
 * twice ("Hilolnashr" beside "Hilol-nashr"), as AuthorMerger does for
 * authors: the editions and announcements, then whatever the kept publisher
 * has left blank (the account that maintains it included), then the name
 * and the slug as an alias, and only then the duplicate itself.
 *
 * The alias keeps it merged: CatalogueRecords and the Asaxiy import match
 * names against aliases too, and the old slug still resolves, so a link to
 * the old page lands on the kept one.
 */
class PublisherMerger
{
    /** Copied over only where the kept publisher has nothing. */
    private const FILLABLE = ['user_id', 'established_year', 'logo_path', 'website_url', 'telegram_channel', 'order_contact'];

    /** Localized, so filled per language. */
    private const TRANSLATABLE = ['bio', 'country'];

    public function __construct(private readonly SiteRevalidator $revalidator) {}

    public function merge(Publisher $duplicate, Publisher $into): void
    {
        if ($duplicate->is($into)) {
            throw new InvalidArgumentException('A publisher cannot be merged into itself.');
        }

        DB::transaction(function () use ($duplicate, $into) {
            // Touched, because the bot's feed (CatalogueSync) orders by
            // updated_at: that is how it learns the printing's new publisher.
            Edition::where('publisher_id', $duplicate->id)->update(['publisher_id' => $into->id, 'updated_at' => now()]);
            // Announcements are deleted with their publisher; these are the kept one's now.
            Announcement::where('publisher_id', $duplicate->id)->update(['publisher_id' => $into->id]);

            $this->fillBlanks($duplicate, $into);

            // Its own aliases come along, then it becomes one.
            PublisherAlias::where('publisher_id', $duplicate->id)->update(['publisher_id' => $into->id]);

            PublisherAlias::create([
                'publisher_id' => $into->id,
                'name' => $duplicate->name,
                // A later publisher who took the same slug and is merged in turn just has none to give.
                'slug' => PublisherAlias::where('slug', $duplicate->slug)->exists() ? null : $duplicate->slug,
            ]);

            $duplicate->delete();
        });

        // Book pages, both publisher pages, every listing: drop the lot.
        $this->revalidator->everything();
    }

    private function fillBlanks(Publisher $duplicate, Publisher $into): void
    {
        foreach (self::FILLABLE as $field) {
            if (blank($into->{$field}) && filled($duplicate->{$field})) {
                $into->{$field} = $duplicate->{$field};
            }
        }

        foreach (self::TRANSLATABLE as $field) {
            foreach ($duplicate->getTranslations($field) as $locale => $value) {
                if (blank($into->getTranslation($field, $locale, false)) && filled($value)) {
                    $into->setTranslation($field, $locale, $value);
                }
            }
        }

        // One account maintains one publisher: it leaves the duplicate before
        // it is given to the kept one.
        if ($into->isDirty('user_id')) {
            $duplicate->forceFill(['user_id' => null])->saveQuietly();
        }

        $into->save();
    }
}
