<?php

namespace Database\Seeders;

use App\Enums\BookRole;
use App\Models\Author;
use App\Models\Book;
use App\Models\Category;
use App\Models\Edition;
use App\Models\Publisher;
use App\Models\Series;
use App\Models\Translator;
use App\Services\CatalogueRecords;
use App\Services\SiteRevalidator;
use App\Support\Asaxiy\AsaxiyCatalog;
use App\Support\Asaxiy\AsaxiyPage;
use App\Support\SearchKeys;
use App\Support\UzbekText;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Imports the asaxiy.uz scrape into the catalogue:
 *
 *     php artisan asaxiy:scrape [--gallery]    # page details + images, optional
 *     php artisan asaxiy:categories            # shelves, optional
 *     php artisan db:seed --class=AsaxiyBooksSeeder
 *
 * Not called from DatabaseSeeder — it needs the 6 MB workbook, which is not
 * in the repository (ASAXIY_BOOKS_FILE, default
 * storage/app/public/asaxiy_books.xlsx). What the two scrape commands saved
 * (ASAXIY_CACHE_DIR, default storage/app/asaxiy) is used when present and
 * simply skipped when not.
 *
 * Each listing becomes an edition. Listings with the same author and title
 * are editions of one book — two publishers, two ISBNs, maybe two
 * translators — and so is a listing of a book somebody already entered by
 * hand. Authors, publishers and translators are matched to existing rows by
 * a spelling-insensitive key (UzbekText::key / publisherKey), so
 * "Hilol nashr", "HILOL-Nashr" and "Ҳилол-нашр" are one publisher.
 * Translators with no profile yet become account-less shell rows.
 *
 * Safe to re-run: an edition is recognised by ISBN, or by Asaxiy's article
 * number when it has none, and on a second pass only its empty columns are
 * filled — images downloaded since the last run get attached, and nothing an
 * admin has edited is overwritten.
 */
class AsaxiyBooksSeeder extends Seeder
{
    private const CHUNK = 500;

    /** @var array<string, int> key => id */
    private array $authors = [];

    /** @var array<string, int> */
    private array $publishers = [];

    /** @var array<string, int> */
    private array $translators = [];

    /** @var array<string, int> author+title key => book id */
    private array $books = [];

    /** @var array<string, int> series name key => id */
    private array $series = [];

    /** @var array<string, int> Asaxiy listing path => category id */
    private array $categories = [];

    /** @var array<string, list<string>> product url => listing paths */
    private array $shelves = [];

    /** @var list<string> article numbers of listings that are not books */
    private array $notBooks = [];

    /** Where asaxiy:scrape and asaxiy:categories saved their results. */
    private string $cacheDir;

    /** @var array<string, int> */
    private array $created = ['books' => 0, 'editions' => 0, 'updated' => 0, 'authors' => 0, 'publishers' => 0, 'translators' => 0, 'covers' => 0, 'series' => 0, 'relations' => 0, 'removed' => 0];

    public function run(): void
    {
        $file = env('ASAXIY_BOOKS_FILE', storage_path('app/public/asaxiy_books.xlsx'));

        if (! is_file($file)) {
            $this->command?->error("Asaxiy workbook not found at {$file}. Set ASAXIY_BOOKS_FILE.");

            return;
        }

        // Every listing is held in memory to de-duplicate across the whole
        // file — about 100 MB for this catalogue, against the production
        // image's 128 MB CLI default. A one-off batch job may have more; the
        // container itself is capped at 384 MB.
        if ($this->memoryLimitBytes() < 256 * 1024 * 1024) {
            ini_set('memory_limit', '256M');
        }

        $this->cacheDir = rtrim(env('ASAXIY_CACHE_DIR', storage_path('app/asaxiy')), '/');
        $listings = $this->listings(new AsaxiyCatalog($file));
        $this->command?->info(count($listings).' distinct editions in the workbook.');

        // Nearly ten thousand saves would otherwise each ping the public
        // site to rebuild a page; one "rebuild everything" at the end does
        // the same job.
        Model::withoutEvents(function () use ($listings) {
            $this->importCategories();
            $this->importPublishers($listings);
            $this->importAuthors($listings);
            $this->importTranslators($listings);
            $this->importSeries($listings);
            $this->importEditions($listings);
            $this->importRelations($listings);
            $this->removeNotBooks();
        });

        // Rows are saved with their keys, but an original name filled in
        // by a bulk update further up is not.
        SearchKeys::rebuild();

        app(SiteRevalidator::class)->everything();

        $c = $this->created;
        $this->command?->info("Books: {$c['books']} new. Editions: {$c['editions']} new, {$c['updated']} updated, {$c['covers']} with a cover.");
        $this->command?->info("Created {$c['authors']} authors, {$c['publishers']} publishers, {$c['translators']} translators, {$c['series']} series; {$c['relations']} related-book links.");
        $this->command?->info(count($this->notBooks)." listings skipped as not books (gift boxes, bundles, puzzles, planners); {$c['removed']} of them removed from an earlier import.");
    }

    /**
     * One entry per edition. Asaxiy lists some printings more than once (a
     * second cover, a restock): the first listing wins and later ones only
     * fill what it left empty. Page details from asaxiy:scrape are merged in
     * where the workbook is silent.
     *
     * @return array<string, array<string, mixed>>
     */
    private function listings(AsaxiyCatalog $catalog): array
    {
        $listings = [];

        foreach ($catalog->books() as $listing) {
            $listing += $this->pageDetails((string) $listing['source_article']);

            // The page's description still has its paragraphs; the
            // workbook's is one line with the structure guessed back.
            $listing['description'] = AsaxiyCatalog::description($listing['page_description'], $listing['uzbek']) ?? $listing['description'];
            unset($listing['page_description']);

            if (AsaxiyCatalog::isNotABook($listing)) {
                $this->notBooks[] = (string) $listing['source_article'];

                continue;
            }

            $id = $listing['isbn'] !== null ? 'isbn:'.$listing['isbn'] : 'article:'.$listing['source_article'];

            if (! isset($listings[$id])) {
                $listings[$id] = $listing;

                continue;
            }

            // A later listing may be tagged with another language; what it
            // contributes to an Uzbek edition has to be Latin like the rest.
            if ($listings[$id]['uzbek'] && ! $listing['uzbek']) {
                $listing = $this->latinize($listing);
            }

            foreach ($listing as $field => $value) {
                if (in_array($field, ['translators', 'related'], true)) {
                    $listings[$id][$field] = array_values(array_unique([...$listings[$id][$field], ...$value]));
                } elseif (blank($listings[$id][$field] ?? null) && filled($value)) {
                    $listings[$id][$field] = $value;
                }
            }
        }

        return $listings;
    }

    /**
     * What asaxiy:scrape saved about the listing that the workbook does not
     * have: the genre crumb, the related books, how many gallery photos
     * there are, and the description as the page laid it out.
     *
     * @return array<string, mixed>
     */
    private function pageDetails(string $article): array
    {
        $path = "{$this->cacheDir}/pages/{$article}.json";
        $page = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return [
            'breadcrumb' => $page['breadcrumb'] ?? null,
            'related' => $page['related'] ?? [],
            'gallery_count' => count($page['gallery'] ?? []),
            'page_description' => $page['description'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $listing
     * @return array<string, mixed>
     */
    private function latinize(array $listing): array
    {
        foreach (['title', 'author', 'publisher', 'description', 'cover_type'] as $field) {
            if (is_string($listing[$field])) {
                $listing[$field] = UzbekText::toLatin($listing[$field]);
            }
        }

        foreach (['authors', 'translators'] as $field) {
            $listing[$field] = array_map(UzbekText::toLatin(...), $listing[$field]);
        }

        return $listing;
    }

    private function importCategories(): void
    {
        foreach (AsaxiyPage::CATEGORIES as $path => $definition) {
            $category = Category::firstOrCreate(
                ['source_path' => "asaxiy:{$path}"],
                ['name' => $definition['name'], 'slug' => Category::generateUniqueSlug($definition['name'])],
            );

            $this->categories[$path] = $category->id;
        }

        foreach (AsaxiyPage::CATEGORIES as $path => $definition) {
            if ($definition['parent'] !== null) {
                Category::whereKey($this->categories[$path])->whereNull('parent_id')
                    ->update(['parent_id' => $this->categories[$definition['parent']]]);
            }
        }

        foreach (glob("{$this->cacheDir}/categories/*.json") ?: [] as $file) {
            $listing = json_decode((string) file_get_contents($file), true);

            foreach ($listing['products'] ?? [] as $url) {
                $this->shelves[$url][] = $listing['path'];
            }
        }
    }

    /** @param  array<string, array<string, mixed>>  $listings */
    private function importPublishers(array $listings): void
    {
        foreach (DB::table('publishers')->select('id', 'name')->cursor() as $publisher) {
            $this->publishers[UzbekText::publisherKey($publisher->name)] ??= $publisher->id;
        }

        $taken = Publisher::pluck('slug')->flip()->all();

        foreach ($this->spellings(array_column($listings, 'publisher'), UzbekText::publisherKey(...)) as $key => $name) {
            if (isset($this->publishers[$key])) {
                continue;
            }

            // Keys apart, but equal to MySQL's accent-insensitive collation
            // (ё/е, ı/i): the unique name index has the last word.
            if ($sameName = Publisher::where('name', $name)->value('id')) {
                $this->publishers[$key] = $sameName;

                continue;
            }

            $this->publishers[$key] = Publisher::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name, 'publisher', $taken),
            ])->id;
            $this->created['publishers']++;
        }
    }

    /** @param  array<string, array<string, mixed>>  $listings */
    private function importAuthors(array $listings): void
    {
        foreach (DB::table('authors')->select('id', 'name', 'original_name')->cursor() as $author) {
            $this->authors[UzbekText::key($author->name)] ??= $author->id;

            if ($author->original_name) {
                $this->authors[UzbekText::key($author->original_name)] ??= $author->id;
            }
        }

        // A spelling merged into another author (AuthorMerger) stays merged.
        foreach (DB::table('author_aliases')->select('author_id', 'name')->cursor() as $alias) {
            $this->authors[UzbekText::key($alias->name)] ??= $alias->author_id;
        }

        $taken = Author::pluck('slug')->flip()->all() + DB::table('author_aliases')->whereNotNull('slug')->pluck('slug')->flip()->all();
        $originals = [];

        foreach ($listings as $listing) {
            foreach ($listing['authors'] as $i => $name) {
                if ($original = $listing['original_authors'][$i] ?? null) {
                    $originals[UzbekText::key($name)] ??= $original;
                }
            }
        }

        foreach ($this->spellings(array_merge(...array_column($listings, 'authors')), UzbekText::key(...)) as $key => $name) {
            if (isset($this->authors[$key])) {
                // Existing row: only fill the original spelling if it had none.
                if (isset($originals[$key])) {
                    Author::whereKey($this->authors[$key])->whereNull('original_name')->update(['original_name' => $originals[$key]]);
                }

                continue;
            }

            // Keys apart, but equal to MySQL's accent-insensitive collation
            // (ё/е, ı/i): the unique name index has the last word.
            if ($sameName = Author::where('name', $name)->value('id')) {
                $this->authors[$key] = $sameName;

                continue;
            }

            $original = $originals[$key] ?? null;

            $this->authors[$key] = Author::create([
                'name' => $name,
                'original_name' => $original !== $name ? $original : null,
                'slug' => $this->uniqueSlug($name, 'author', $taken),
            ])->id;
            $this->created['authors']++;
        }
    }

    /** @param  array<string, array<string, mixed>>  $listings */
    private function importTranslators(array $listings): void
    {
        foreach (DB::table('translators')->select('id', 'name')->cursor() as $translator) {
            $this->translators[UzbekText::key($translator->name)] ??= $translator->id;
        }

        $taken = Translator::pluck('slug')->flip()->all();

        foreach ($this->spellings(array_merge(...array_column($listings, 'translators')), UzbekText::key(...)) as $key => $name) {
            if (isset($this->translators[$key])) {
                continue;
            }

            // A shell: name and slug, no login. An admin attaches an account
            // from the Translators resource if the person ever claims it.
            $this->translators[$key] = Translator::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name, 'translator', $taken),
            ])->id;
            $this->created['translators']++;
        }
    }

    /**
     * A "series" found in one title only is just a title with a number in
     * it. Only names at least two distinct books share become series.
     *
     * @param  array<string, array<string, mixed>>  $listings
     */
    private function importSeries(array $listings): void
    {
        $firstAuthors = $this->firstAuthors();

        foreach (DB::table('books')->join('series', 'series.id', '=', 'books.series_id')->select('books.id', 'series.id as series_id', 'series.name')->cursor() as $row) {
            $this->series[$this->seriesKey($firstAuthors[$row->id] ?? null, $row->name)] ??= $row->series_id;
        }

        $books = [];
        $names = [];

        foreach ($listings as $listing) {
            if ($listing['series'] === null) {
                continue;
            }

            $key = $this->seriesKey($listing['authors'][0] ?? null, $listing['series']['name']);
            $books[$key][$this->bookKey($listing)] = true;
            $names[$key] ??= $listing['series']['name'];
        }

        foreach ($books as $key => $members) {
            if (count($members) < 2 || isset($this->series[$key])) {
                continue;
            }

            $this->series[$key] = Series::create([
                'name' => $names[$key],
                'slug' => Series::generateUniqueSlug($names[$key]),
            ])->id;
            $this->created['series']++;
        }
    }

    /** @param  array<string, array<string, mixed>>  $listings */
    private function importEditions(array $listings): void
    {
        // Books already in the catalogue, under the same key new listings
        // get, so an Asaxiy edition of a book somebody entered by hand joins
        // that book instead of starting a second one.
        //
        // Plain rows rather than models: nine thousand books with their
        // authors as Eloquent objects is what pushed a re-run past PHP's
        // 128 MB on the server.
        $firstAuthors = $this->firstAuthors();

        foreach (DB::table('books')->select('id', 'uzbek_title')->cursor() as $book) {
            $this->books[$this->keyFor($firstAuthors[$book->id] ?? null, $book->uzbek_title)] ??= $book->id;
        }

        $byIsbn = Edition::whereNotNull('isbn')->pluck('id', 'isbn')->all();
        $byArticle = Edition::whereNotNull('source_article')->pluck('id', 'source_article')->all();
        $disk = Storage::disk('public');

        foreach (array_chunk($listings, self::CHUNK) as $chunk) {
            DB::transaction(function () use ($chunk, $byIsbn, $byArticle, $disk) {
                foreach ($chunk as $listing) {
                    $existingId = ($listing['isbn'] !== null ? ($byIsbn[$listing['isbn']] ?? null) : null)
                        ?? $byArticle[$listing['source_article']] ?? null;

                    $attributes = $this->editionAttributes($listing, $disk);

                    if ($existingId !== null) {
                        $edition = Edition::find($existingId);
                        $this->fillBlanks($edition, $attributes);
                    } else {
                        $edition = Edition::create([...$attributes, 'book_id' => $this->bookFor($listing)->id]);
                        $this->created['editions']++;
                    }

                    $this->attachCredits($edition, $listing);
                    $this->attachGallery($edition, $listing, $disk);
                    $this->attachShelves($edition->book_id, $listing);

                    if ($edition->cover_path !== null) {
                        $this->created['covers']++;
                    }
                }
            });
        }
    }

    /** @param  array<string, mixed>  $listing */
    private function bookFor(array $listing): Book
    {
        $key = $this->bookKey($listing);

        if (isset($this->books[$key])) {
            return Book::find($this->books[$key]);
        }

        $series = $listing['series'] !== null ? $this->series[$this->seriesKey($listing['authors'][0] ?? null, $listing['series']['name'])] ?? null : null;

        $book = Book::create([
            'uzbek_title' => Str::limit($listing['title'], 250, '…'),
            // An untranslated book is in its original language; for a
            // translation the listing does not say what it was translated from.
            'source_language' => $listing['translators'] === [] && $listing['language'] !== null
                ? Str::before($listing['language'], ',')
                : null,
            'series_id' => $series,
            'series_position' => $series !== null ? $listing['series']['position'] : null,
        ]);

        foreach (array_values($listing['authors']) as $position => $name) {
            if ($authorId = $this->authors[UzbekText::key($name)] ?? null) {
                $book->authors()->syncWithoutDetaching([$authorId => ['position' => $position]]);
            }
        }

        $this->books[$key] = $book->id;
        $this->created['books']++;

        return $book;
    }

    /**
     * @param  array<string, mixed>  $listing
     * @return array<string, mixed>
     */
    private function editionAttributes(array $listing, Filesystem $disk): array
    {
        $cover = $listing['image_key'] !== null ? "downloads/{$listing['image_key']}.webp" : null;

        return [
            'publisher_id' => $listing['publisher'] !== null ? $this->publishers[UzbekText::publisherKey($listing['publisher'])] ?? null : null,
            'isbn' => $listing['isbn'],
            'language' => $listing['language'],
            'script' => $listing['script'],
            'year' => $listing['year'],
            'pages' => $listing['pages'],
            'cover_type' => $listing['cover_type'],
            'cover_material' => $listing['cover_material'],
            'paper_format' => $listing['paper_format'],
            'page_surface' => $listing['page_surface'],
            'print_country' => $listing['print_country'],
            'weight' => $listing['weight'],
            'dimensions' => $listing['dimensions'],
            'volume_count' => $listing['volume_count'],
            'volume_part' => $listing['volume_part'],
            'cover_path' => $cover !== null && $disk->exists($cover) ? $cover : null,
            'description' => $listing['description'],
            'source_url' => $listing['source_url'],
            'source_article' => $listing['source_article'],
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private function fillBlanks(Edition $edition, array $attributes): void
    {
        foreach ($attributes as $column => $value) {
            if ($value !== null && blank($edition->getAttribute($column))) {
                $edition->setAttribute($column, $value);
            }
        }

        if ($edition->isDirty()) {
            $edition->save();
            $this->created['updated']++;
        }
    }

    /** @param  array<string, mixed>  $listing */
    private function attachCredits(Edition $edition, array $listing): void
    {
        $credited = $edition->translators()->pluck('translators.id')->all();
        $position = count($credited);

        foreach ($listing['translators'] as $name) {
            $id = $this->translators[UzbekText::key($name)] ?? null;

            if ($id !== null && ! in_array($id, $credited, true)) {
                $edition->translators()->attach($id, ['role' => BookRole::Translator->value, 'position' => $position++]);
                $credited[] = $id;
            }
        }
    }

    /** Gallery photos asaxiy:scrape --gallery saved as <key>-2.webp, <key>-3.webp … */
    private function attachGallery(Edition $edition, array $listing, Filesystem $disk): void
    {
        if ($listing['image_key'] === null || $listing['gallery_count'] === 0 || $edition->images()->exists()) {
            return;
        }

        for ($n = 2; $n <= $listing['gallery_count'] + 1; $n++) {
            $path = "downloads/{$listing['image_key']}-{$n}.webp";

            if ($disk->exists($path)) {
                $edition->images()->create(['path' => $path, 'position' => $n]);
            }
        }
    }

    /**
     * Every Asaxiy shelf the listing sits on. The breadcrumb names its main
     * shelf, which becomes the book's genre.
     *
     * @param  array<string, mixed>  $listing
     */
    private function attachShelves(int $bookId, array $listing): void
    {
        $paths = $this->shelves[$listing['source_url']] ?? [];
        $primary = null;

        foreach (AsaxiyPage::CATEGORIES as $path => $definition) {
            if ($listing['breadcrumb'] !== null && UzbekText::key($definition['name']) === UzbekText::key($listing['breadcrumb'])) {
                $primary = $path;
                $paths[] = $path;
            }
        }

        if ($paths === []) {
            return;
        }

        $alreadyPrimary = DB::table('book_category')->where('book_id', $bookId)->where('is_primary', true)->exists();

        foreach (array_unique($paths) as $path) {
            DB::table('book_category')->insertOrIgnore([
                'book_id' => $bookId,
                'category_id' => $this->categories[$path],
                'is_primary' => ! $alreadyPrimary && $path === $primary,
            ]);
        }
    }

    /**
     * Asaxiy's "Tavsiya" block, as book-to-book links. Run after every
     * edition exists, since a recommendation can point at a book imported
     * later in the same file.
     *
     * @param  array<string, array<string, mixed>>  $listings
     */
    private function importRelations(array $listings): void
    {
        $bookByUrl = Edition::whereNotNull('source_url')->pluck('book_id', 'source_url')->all();

        foreach ($listings as $listing) {
            $bookId = $bookByUrl[$listing['source_url']] ?? null;

            if ($bookId === null) {
                continue;
            }

            foreach (array_values($listing['related']) as $position => $url) {
                $relatedId = $bookByUrl[$url] ?? null;

                if ($relatedId === null || $relatedId === $bookId) {
                    continue;
                }

                $this->created['relations'] += DB::table('book_relations')->insertOrIgnore([
                    'book_id' => $bookId,
                    'related_book_id' => $relatedId,
                    'source' => 'asaxiy',
                    'position' => $position,
                ]);
            }
        }
    }

    /**
     * An earlier run imported the gift boxes and bundles as books. Their
     * editions go now, and each book goes with its last edition — unless
     * somebody reviewed it, in which case it is a person's call, not the
     * seeder's.
     */
    private function removeNotBooks(): void
    {
        if ($this->notBooks === []) {
            return;
        }

        $editions = Edition::whereIn('source_article', $this->notBooks)->whereDoesntHave('taqrizlar')->get();

        foreach ($editions as $edition) {
            $edition->delete();
            $this->created['removed']++;

            if (! Edition::where('book_id', $edition->book_id)->exists()) {
                Book::whereKey($edition->book_id)->delete();
            }
        }
    }

    private function memoryLimitBytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $number = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** @return array<int, string> book id => its first-credited author's name */
    private function firstAuthors(): array
    {
        $first = [];

        $rows = DB::table('book_author')
            ->join('authors', 'authors.id', '=', 'book_author.author_id')
            ->orderBy('book_author.book_id')
            ->orderBy('book_author.position')
            ->select('book_author.book_id', 'authors.name')
            ->cursor();

        foreach ($rows as $row) {
            $first[$row->book_id] ??= $row->name;
        }

        return $first;
    }

    /** @param  array<string, mixed>  $listing */
    private function bookKey(array $listing): string
    {
        return $this->keyFor($listing['authors'][0] ?? null, $listing['title']);
    }

    /** A series is one author's: two writers' "Tanlangan asarlar" are two series. */
    private function seriesKey(?string $author, string $name): string
    {
        return $this->keyFor($author, $name);
    }

    private function keyFor(?string $author, string $title): string
    {
        return CatalogueRecords::bookKey($author, $title);
    }

    /**
     * Groups every spelling of a name under its key and picks the one to
     * store: Latin over Cyrillic (the catalogue is Latin; the other script
     * goes to original_name), then the most common, then mixed case over
     * ALL CAPS.
     *
     * @param  array<int, ?string>  $names
     * @return array<string, string> key => chosen spelling
     */
    private function spellings(array $names, callable $key): array
    {
        $counts = [];
        foreach (array_filter($names) as $name) {
            $k = $key($name);

            if ($k !== '') {
                $counts[$k][$name] = ($counts[$k][$name] ?? 0) + 1;
            }
        }

        return array_map(function (array $variants): string {
            uksort($variants, fn (string $a, string $b) => [UzbekText::hasCyrillic($a), $variants[$b], $this->isShouting($a)]
                <=> [UzbekText::hasCyrillic($b), $variants[$a], $this->isShouting($b)]);

            return array_key_first($variants);
        }, $counts);
    }

    private function isShouting(string $name): bool
    {
        return mb_strtoupper($name) === $name && mb_strtolower($name) !== $name;
    }

    /** @param  array<string, mixed>  $taken  slugs already used, updated in place */
    private function uniqueSlug(string $name, string $fallback, array &$taken): string
    {
        $base = Str::slug($name) ?: $fallback;

        // A slug that is all digits would be read as a primary key by
        // resolveRouteBinding.
        if (ctype_digit($base)) {
            $base = "{$fallback}-{$base}";
        }

        $slug = $base;
        for ($suffix = 2; isset($taken[$slug]); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        $taken[$slug] = true;

        return $slug;
    }
}
