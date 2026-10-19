<?php

namespace App\Services;

use App\Models\Author;
use App\Models\AuthorAlias;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Folds one author into another when the catalogue holds the same person
 * twice ("Toxir Malik" beside "Tohir Malik"): the books, then whatever the
 * kept author has left blank, then the spelling and the slug as an alias,
 * and only then the duplicate itself.
 *
 * The alias is what keeps it merged. CatalogueRecords and the Asaxiy import
 * match names against aliases too, so neither the bot nor a re-import makes
 * the duplicate again; and the old slug still resolves, so a link to the old
 * page lands on the kept one.
 *
 * Which two rows are one person is a person's call, not this class's: "M.
 * Ahmedov" and "M. Axmedov" may well be two people.
 */
class AuthorMerger
{
    /** Copied over only where the kept author has nothing. */
    private const FILLABLE = ['original_name', 'portrait_path', 'website_url', 'birth_year', 'death_year'];

    /** Localized, so filled per language. */
    private const TRANSLATABLE = ['bio', 'nationality'];

    public function __construct(private readonly SiteRevalidator $revalidator) {}

    public function merge(Author $duplicate, Author $into): void
    {
        if ($duplicate->is($into)) {
            throw new InvalidArgumentException('An author cannot be merged into itself.');
        }

        DB::transaction(function () use ($duplicate, $into) {
            $this->moveBooks($duplicate, $into);
            $this->fillBlanks($duplicate, $into);

            // Its own aliases come along, then it becomes one.
            AuthorAlias::where('author_id', $duplicate->id)->update(['author_id' => $into->id]);

            // Spellings the kept author does not already answer to.
            $names = array_values(array_diff(
                array_unique(array_filter([$duplicate->name, $duplicate->original_name])),
                array_filter([$into->name, $into->original_name]),
            ));

            // The old page's slug rides on the first; a later author who took
            // the same slug and is merged in turn just has none to give.
            AuthorAlias::create([
                'author_id' => $into->id,
                'name' => $names[0] ?? $duplicate->name,
                'slug' => AuthorAlias::where('slug', $duplicate->slug)->exists() ? null : $duplicate->slug,
            ]);

            foreach (array_slice($names, 1) as $name) {
                AuthorAlias::create(['author_id' => $into->id, 'name' => $name]);
            }

            $duplicate->delete();
        });

        // Book pages, both author pages, every listing: drop the lot.
        $this->revalidator->everything();
    }

    private function moveBooks(Author $duplicate, Author $into): void
    {
        $already = DB::table('book_author')->where('author_id', $into->id)->pluck('book_id')->all();

        // A book credited to both keeps the kept author's place in the credits.
        DB::table('book_author')
            ->where('author_id', $duplicate->id)
            ->whereIn('book_id', $already)
            ->delete();

        DB::table('book_author')
            ->where('author_id', $duplicate->id)
            ->update(['author_id' => $into->id]);
    }

    private function fillBlanks(Author $duplicate, Author $into): void
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

        $into->save();
    }
}
