<?php

namespace Tests\Feature;

use App\Enums\ModerationStatus;
use App\Models\Book;
use App\Models\BookMerge;
use App\Models\Category;
use App\Models\Edition;
use App\Services\BookMerger;
use App\Services\CatalogueRecords;
use App\Services\CatalogueSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * One work entered twice ("11/22/63" beside "11/22/63 (A5)") becomes one
 * book with both editions, and stays one: the old page address arrives,
 * the bot and imports find the old title, and the merge can be undone.
 */
class BookMergeTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    public function test_everything_hanging_off_the_duplicate_moves_to_the_kept_book(): void
    {
        $king = $this->author('Stephen King');
        $kept = $this->catalogueBook(['uzbek_title' => '11/22/63', 'description' => 'Own text'], ['isbn' => '9785170000001'], [$king]);
        $dup = $this->catalogueBook(['uzbek_title' => '11/22/63 (A5)', 'description' => 'Copied text', 'original_title' => '11/22/63'], ['isbn' => '9785170000002'], [$king]);
        $kept->update(['original_title' => null]);
        $other = $this->catalogueBook(['uzbek_title' => 'Misery'], ['isbn' => '9785170000003'], [$king]);
        $dupEdition = $dup->editions()->sole();
        Edition::whereKey($dupEdition->id)->toBase()->update(['updated_at' => '2026-01-01 10:00:00']);

        // Shelves: the kept book's genre stays; the duplicate's other shelf joins.
        $novels = Category::create(['name' => 'Romanlar', 'slug' => 'romanlar']);
        $top = Category::create(['name' => 'TOP-100', 'slug' => 'top-100']);
        $thrillers = Category::create(['name' => 'Trillerlar', 'slug' => 'trillerlar']);
        $kept->setGenre($novels);
        $dup->setGenre($thrillers);
        DB::table('book_category')->insert(['book_id' => $dup->id, 'category_id' => $top->id, 'is_primary' => false]);

        // Recommendations both ways, one between the two of them.
        DB::table('book_relations')->insert([
            ['book_id' => $dup->id, 'related_book_id' => $other->id, 'source' => 'asaxiy', 'position' => 0],
            ['book_id' => $other->id, 'related_book_id' => $dup->id, 'source' => 'asaxiy', 'position' => 0],
            ['book_id' => $dup->id, 'related_book_id' => $kept->id, 'source' => 'asaxiy', 'position' => 1],
        ]);

        // Views: one day both were opened, one day only the duplicate.
        DB::table('book_views')->insert([
            ['book_id' => $kept->id, 'day' => '2026-11-01', 'locale' => 'uz', 'views' => 5],
            ['book_id' => $dup->id, 'day' => '2026-11-01', 'locale' => 'uz', 'views' => 3],
            ['book_id' => $dup->id, 'day' => '2026-11-02', 'locale' => 'ru', 'views' => 2],
        ]);

        // A collection with both, one with only the duplicate.
        $both = DB::table('collections')->insertGetId(['slug' => 'both', 'title' => '{"uz":"Ikkalasi"}']);
        $only = DB::table('collections')->insertGetId(['slug' => 'only', 'title' => '{"uz":"Bittasi"}']);
        DB::table('collection_items')->insert([
            ['collection_id' => $both, 'book_id' => $kept->id],
            ['collection_id' => $both, 'book_id' => $dup->id],
            ['collection_id' => $only, 'book_id' => $dup->id],
        ]);

        // A library that holds both printings has the copies of both.
        $library = DB::table('libraries')->insertGetId(['slug' => 'navoiy', 'name' => 'Navoiy', 'region' => 'Toshkent']);
        $another = DB::table('libraries')->insertGetId(['slug' => 'bobur', 'name' => 'Bobur', 'region' => 'Andijon']);
        DB::table('library_holdings')->insert([
            ['library_id' => $library, 'book_id' => $kept->id, 'copies_total' => 2, 'copies_available' => 1],
            ['library_id' => $library, 'book_id' => $dup->id, 'copies_total' => 3, 'copies_available' => 3],
            ['library_id' => $another, 'book_id' => $dup->id, 'copies_total' => 1, 'copies_available' => 0],
        ]);

        $quote = DB::table('quotes')->insertGetId(['book_id' => $dup->id, 'body' => 'Iqtibos', 'status' => 'approved']);
        $review = DB::table('reader_reviews')->insertGetId(['external_id' => 'r1', 'book_id' => $dup->id, 'edition_id' => $dupEdition->id, 'body' => 'Zo\'r', 'written_at' => now()]);
        $click = DB::table('outbound_clicks')->insertGetId(['kind' => 'store', 'book_id' => $dup->id, 'target' => 'asaxiy']);

        $report = app(BookMerger::class)->merge($dup, $kept);

        $this->assertNull(Book::find($dup->id));
        $kept->refresh();

        // Editions, touched so the bot's feed serves them again.
        $this->assertSame([$kept->id], Edition::whereIn('isbn', ['9785170000001', '9785170000002'])->pluck('book_id')->unique()->values()->all());
        $this->assertNotSame('2026-01-01 10:00:00', (string) $dupEdition->fresh()->updated_at);

        // The kept book's own words stand; its blanks are filled.
        $this->assertSame('11/22/63', $kept->uzbek_title);
        $this->assertSame('Own text', $kept->description);
        $this->assertSame('11/22/63', $kept->original_title);
        $this->assertSame([$king->id], $kept->authors()->pluck('authors.id')->all());

        $this->assertSame($novels->id, $kept->genre()?->id);
        $this->assertEqualsCanonicalizing([$novels->id, $thrillers->id, $top->id], $kept->categories()->pluck('categories.id')->all());

        $this->assertSame(
            [[$kept->id, $other->id], [$other->id, $kept->id]],
            DB::table('book_relations')->orderBy('id')->get()->map(fn ($r) => [(int) $r->book_id, (int) $r->related_book_id])->all(),
        );

        $this->assertSame(
            [['2026-11-01', 'uz', 8], ['2026-11-02', 'ru', 2]],
            DB::table('book_views')->where('book_id', $kept->id)->orderBy('day')->get()->map(fn ($r) => [$r->day, $r->locale, (int) $r->views])->all(),
        );

        $this->assertSame([$both, $only], DB::table('collection_items')->where('book_id', $kept->id)->orderBy('collection_id')->pluck('collection_id')->map(intval(...))->all());
        $this->assertSame(1, DB::table('collection_items')->where('collection_id', $both)->count());

        $this->assertSame(
            [[$library, 5, 4], [$another, 1, 0]],
            DB::table('library_holdings')->where('book_id', $kept->id)->orderBy('library_id')->get()->map(fn ($r) => [(int) $r->library_id, (int) $r->copies_total, (int) $r->copies_available])->all(),
        );

        $this->assertSame($kept->id, (int) DB::table('quotes')->where('id', $quote)->value('book_id'));
        $this->assertSame($kept->id, (int) DB::table('reader_reviews')->where('id', $review)->value('book_id'));
        $this->assertSame($kept->id, (int) DB::table('outbound_clicks')->where('id', $click)->value('book_id'));

        $merge = BookMerge::sole();
        $this->assertSame($dup->id, (int) $merge->merged_book_id);
        $this->assertSame($kept->id, (int) $merge->book_id);
        $this->assertSame('11/22/63 (A5)', $merge->merged_title);
        $this->assertSame($merge->id, $report['merge_id']);
        $this->assertSame(['original_title'], $report['filled']);
        $this->assertSame(1, $report['retitled']);
    }

    public function test_an_empty_kept_book_takes_the_duplicates_authors_and_genre(): void
    {
        $kept = $this->catalogueBook(['uzbek_title' => 'Черный лебедь'], ['isbn' => '9785389000001'], []);
        $dup = $this->catalogueBook(['uzbek_title' => 'Черный лебедь (Издание второе)', 'description' => 'Matn'], ['isbn' => '9785389000002'], [$this->author('Nassim Taleb')]);
        $genre = Category::create(['name' => 'Biznes', 'slug' => 'biznes']);
        $dup->setGenre($genre);

        app(BookMerger::class)->merge($dup, $kept);

        $kept->refresh();
        $this->assertSame(['Nassim Taleb'], $kept->authors()->pluck('name')->all());
        $this->assertSame($genre->id, $kept->genre()?->id);
        $this->assertSame('Matn', $kept->description);
    }

    public function test_a_title_typed_into_the_author_field_is_not_carried_over(): void
    {
        $kept = $this->catalogueBook(['uzbek_title' => 'Nick Hornby - How To Be Good (used)'], ['isbn' => '9780140000001'], []);
        $dup = $this->catalogueBook(['uzbek_title' => 'Nick Hornby - How To Be Good (used)'], ['isbn' => '9780140000002'], [$this->author('Nick Hornby - How To Be Good (used)')]);

        app(BookMerger::class)->merge($dup, $kept);

        $this->assertSame([], $kept->fresh()->authors()->pluck('name')->all());
    }

    public function test_one_language_spelt_two_ways_is_not_a_warning(): void
    {
        $kept = $this->catalogueBook(['uzbek_title' => 'Пикник на обочине'], ['language' => 'Rus tili']);
        $dup = $this->catalogueBook(['uzbek_title' => 'Пикник на обочине (A6)'], ['language' => 'Ruscha']);

        $this->assertSame([], app(BookMerger::class)->warnings($dup, $kept));
    }

    public function test_the_warnings_name_what_a_person_should_look_at(): void
    {
        $kept = $this->catalogueBook(['uzbek_title' => 'Idealist o\'qituvchi', 'description' => 'A'], ['language' => "O'zbek tili"], [$this->author('Grigoriy Petrov')]);
        $dup = $this->catalogueBook(['uzbek_title' => 'The Ideal Teacher', 'description' => 'B'], ['language' => 'Ingliz tili'], [$this->author('G. Petrov')]);

        $warnings = implode("\n", app(BookMerger::class)->warnings($dup, $kept));

        $this->assertStringContainsString('authors differ', $warnings);
        $this->assertStringContainsString('both have their own description', $warnings);
        $this->assertStringContainsString('no language in common', $warnings);
    }

    public function test_the_old_address_answers_with_the_kept_book(): void
    {
        $kept = $this->catalogueBook(['uzbek_title' => 'Persuasion'], ['isbn' => '9780141000001']);
        $dup = $this->catalogueBook(['uzbek_title' => 'Jane Austen: Persuasion'], ['isbn' => '9780141000002']);
        $third = $this->catalogueBook(['uzbek_title' => 'Persuasion (Penguin)'], ['isbn' => '9780141000003']);

        app(BookMerger::class)->merge($dup, $kept);

        $this->getJson("/api/books/{$dup->id}")->assertOk()->assertJsonPath('data.id', (string) $kept->id);
        $this->getJson("/api/books/{$kept->id}")->assertOk()->assertJsonPath('data.id', (string) $kept->id);
        $this->getJson('/api/books/999999')->assertNotFound();
        $this->getJson('/api/books/abc')->assertNotFound();

        // Merged again in turn: the first old address follows to the end.
        app(BookMerger::class)->merge($kept->fresh(), $third);

        $this->getJson("/api/books/{$dup->id}")->assertOk()->assertJsonPath('data.id', (string) $third->id);
        $this->getJson("/api/books/{$kept->id}")->assertOk()->assertJsonPath('data.id', (string) $third->id);
    }

    public function test_the_bot_or_an_import_sending_the_old_title_joins_the_kept_book(): void
    {
        config(['catalogue.sync_token' => 'secret']);
        $kept = $this->catalogueBook(['uzbek_title' => 'Kalila va Dimna'], ['isbn' => '9789943000101'], [$this->author('Bidpay')]);
        $dup = $this->catalogueBook(['uzbek_title' => 'Kalila va Dimna (Hind eposi)'], ['isbn' => '9789943000102'], [$this->author('Bidpay')]);

        app(BookMerger::class)->merge($dup, $kept);

        $this->assertSame($kept->id, app(CatalogueRecords::class)->findBook('Bidpay', 'Kalila va Dimna (Hind eposi)')?->id);

        $this->withToken('secret')->putJson('/api/sync/editions/9789943000103', [
            'title' => 'Kalila va Dimna (Hind eposi)',
            'authors' => ['Bidpay'],
            'updatedAt' => '2026-11-01T10:00:00Z',
        ])->assertOk()->assertJsonPath('outcome', 'created')->assertJsonPath('bookId', $kept->id);

        $this->assertSame(1, Book::count());
    }

    public function test_the_bot_hears_of_a_moved_edition_with_the_kept_books_title_and_authors(): void
    {
        $sync = app(CatalogueSync::class);
        $kept = $this->catalogueBook(['uzbek_title' => 'Uch ildiz'], ['isbn' => '9789943000201'], [$this->author('Pirimqul Qodirov')]);
        $dup = $this->catalogueBook(['uzbek_title' => 'Uch ildiz (Turon zamin)'], ['isbn' => '9789943000202'], [$this->author('P. Qodirov')]);

        // Both already in step with the bot: the feed has nothing to say.
        foreach (Edition::with(['book.authors', 'book.categories', 'publisher', 'translators'])->get() as $edition) {
            Edition::whereKey($edition->id)->toBase()->update(['sync_hash' => $sync->hash($sync->projection($edition)), 'updated_at' => '2026-01-01 10:00:00']);
        }
        $this->assertSame([], $sync->feed(null, null, 100)['items']);

        app(BookMerger::class)->merge($dup, $kept);

        $items = $sync->feed(now()->subMinute(), null, 100)['items'];
        $this->assertCount(1, $items);
        $this->assertSame('9789943000202', $items[0]['isbn']);
        $this->assertSame('Uch ildiz', $items[0]['title']);
        $this->assertSame(['Pirimqul Qodirov'], $items[0]['authors']);
    }

    public function test_unmerge_puts_everything_back(): void
    {
        $kept = $this->catalogueBook(['uzbek_title' => 'Процесс'], ['isbn' => '9785170000301']);
        $dup = $this->catalogueBook(['uzbek_title' => 'Процесс (A6)', 'description' => 'Matn', 'original_title' => 'Der Process'], ['isbn' => '9785170000302']);
        $kept->update(['original_title' => null]);
        $other = $this->catalogueBook(['uzbek_title' => 'Замок'], ['isbn' => '9785170000303']);
        $genre = Category::create(['name' => 'Klassika', 'slug' => 'klassika']);
        $dup->setGenre($genre);
        DB::table('book_relations')->insert([
            ['book_id' => $dup->id, 'related_book_id' => $other->id, 'source' => 'asaxiy', 'position' => 0],
            ['book_id' => $dup->id, 'related_book_id' => $kept->id, 'source' => 'asaxiy', 'position' => 1],
        ]);
        DB::table('book_views')->insert([
            ['book_id' => $kept->id, 'day' => '2026-11-01', 'locale' => 'uz', 'views' => 5],
            ['book_id' => $dup->id, 'day' => '2026-11-01', 'locale' => 'uz', 'views' => 3],
        ]);
        $library = DB::table('libraries')->insertGetId(['slug' => 'navoiy', 'name' => 'Navoiy', 'region' => 'Toshkent']);
        DB::table('library_holdings')->insert([
            ['library_id' => $library, 'book_id' => $kept->id, 'copies_total' => 2, 'copies_available' => 1],
            ['library_id' => $library, 'book_id' => $dup->id, 'copies_total' => 3, 'copies_available' => 3],
        ]);
        $before = $this->state($kept->id, $dup->id);

        $merger = app(BookMerger::class);
        $merger->merge($dup, $kept);
        $merger->unmerge(BookMerge::sole());

        $this->assertSame($before, $this->state($kept->id, $dup->id));
        $this->assertSame(0, BookMerge::count());
        $this->assertSame('Процесс (A6)', Book::find($dup->id)?->uzbek_title);
    }

    public function test_the_command_dry_run_changes_nothing_and_a_real_run_merges_and_logs(): void
    {
        Storage::fake('local');
        $kept = $this->catalogueBook(['uzbek_title' => 'Десять негритят'], ['isbn' => '9785040000001']);
        $dup = $this->catalogueBook(['uzbek_title' => 'Десять негритят (мягкий переплёт)'], ['isbn' => '9785040000002']);
        $pending = $this->catalogueBook(['uzbek_title' => 'Pending', 'status' => ModerationStatus::Pending], ['isbn' => '9785040000003']);
        $approved = $this->catalogueBook(['uzbek_title' => 'Approved'], ['isbn' => '9785040000004']);

        $csv = tempnam(sys_get_temp_dir(), 'merge');
        file_put_contents($csv, "keep_id,merge_id,note\n{$kept->id},{$dup->id},\"soft cover\"\n# a comment\n\n{$pending->id},{$approved->id},\n{$kept->id},424242,\n");

        $this->artisan('books:merge', ['file' => $csv, '--dry-run' => true])
            ->expectsOutputToContain('would be merged')
            ->assertSuccessful();

        $this->assertSame(4, Book::count());
        $this->assertSame(0, BookMerge::count());
        $this->assertCount(1, Storage::disk('local')->files('book-merges'));

        $this->artisan('books:merge', ['file' => $csv, '--force' => true])
            ->expectsOutputToContain('Merged 1 books, 2 skipped.')
            ->assertSuccessful();

        $this->assertNull(Book::find($dup->id));
        $this->assertNotNull(Book::find($approved->id), 'a pending book does not swallow one on the site');
        $this->assertSame($kept->id, BookMerge::keptIdFor($dup->id));
        $this->assertCount(3, Storage::disk('local')->files('book-merges'));

        $this->artisan('books:unmerge', ['ids' => [$dup->id], '--force' => true])->assertSuccessful();
        $this->assertNotNull(Book::find($dup->id));
    }

    public function test_a_file_with_conflicting_rows_merges_nothing(): void
    {
        $a = $this->catalogueBook(['uzbek_title' => 'A'], ['isbn' => '9785040000011']);
        $b = $this->catalogueBook(['uzbek_title' => 'B'], ['isbn' => '9785040000012']);
        $c = $this->catalogueBook(['uzbek_title' => 'C'], ['isbn' => '9785040000013']);

        $csv = tempnam(sys_get_temp_dir(), 'merge');
        file_put_contents($csv, "{$a->id},{$b->id}\n{$b->id},{$c->id}\n");

        $this->artisan('books:merge', ['file' => $csv, '--force' => true])
            ->expectsOutputToContain('both merged and kept')
            ->assertFailed();

        $this->assertSame(3, Book::count());
    }

    /** Everything the merge touches, for both books, as plain arrays. */
    private function state(int $keptId, int $dupId): array
    {
        $ids = [$keptId, $dupId];
        $rows = fn (string $table, array $columns, string $key = 'book_id') => DB::table($table)->whereIn($key, $ids)->orderBy('id')->get($columns)->map(fn ($r) => array_map(strval(...), (array) $r))->all();

        return [
            'books' => $rows('books', ['id', 'uzbek_title', 'original_title', 'description', 'source_language', 'status'], 'id'),
            'editions' => $rows('editions', ['id', 'book_id', 'isbn']),
            'authors' => $rows('book_author', ['book_id', 'author_id', 'position']),
            'shelves' => DB::table('book_category')->whereIn('book_id', $ids)->orderBy('book_id')->orderBy('category_id')->get(['book_id', 'category_id', 'is_primary'])->map(fn ($r) => array_map(strval(...), (array) $r))->all(),
            'relations' => DB::table('book_relations')->orderBy('id')->get(['id', 'book_id', 'related_book_id', 'source', 'position'])->map(fn ($r) => array_map(strval(...), (array) $r))->all(),
            'views' => $rows('book_views', ['id', 'book_id', 'day', 'locale', 'views']),
            'holdings' => $rows('library_holdings', ['id', 'book_id', 'library_id', 'copies_total', 'copies_available']),
        ];
    }
}
