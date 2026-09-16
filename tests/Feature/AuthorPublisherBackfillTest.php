<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\Publisher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Before the catalogue tables existed, a book named its author and publisher
 * with free text — so two translators who had both worked on Hemingway each
 * typed his name into their own rows. The two 2026_09_14 migrations turn
 * those strings into shared `authors` / `publishers` rows and point every
 * book at one.
 *
 * That conversion runs exactly once, against production data nobody can
 * replay, so it is worth a test: a slug collision or an unmatched name is
 * not something you get a second chance at.
 *
 * DatabaseMigrations rather than RefreshDatabase because these tests roll
 * the schema back and forward again. RefreshDatabase wraps each test in a
 * transaction, and MySQL commits it out from under us on the first DDL
 * statement, leaving the suite's tables gone for whatever runs next.
 */
class AuthorPublisherBackfillTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Rewinds to the schema as it was before the catalogue existed: books
     * with free-text author/publisher columns and no foreign keys.
     */
    private function rewindToLegacySchema(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('authors'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('books', 'author_id'));
    }

    private function replayTheMigrations(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    /**
     * Inserts through the query builder, not the model: at this point in
     * history author_id and publisher_id do not exist, and Book's fillable
     * list would try to write them.
     */
    private function legacyBook(string $author, ?string $publisher, string $title = 'Kitob'): int
    {
        $translatorId = DB::table('translators')->insertGetId([
            'user_id' => DB::table('users')->insertGetId([
                'name' => 'Translator '.$title,
                'email' => strtolower(str_replace(' ', '-', $title)).'@example.test',
                'password' => bcrypt('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'slug' => 'translator-'.strtolower(str_replace(' ', '-', $title)),
            'name' => 'Translator '.$title,
            'years_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('books')->insertGetId([
            'translator_id' => $translatorId,
            'uzbek_title' => $title,
            'original_title' => $title,
            'author' => $author,
            'publisher' => $publisher,
            'source_language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_every_distinct_author_name_becomes_one_catalogue_row(): void
    {
        $this->rewindToLegacySchema();

        $this->legacyBook('Ernest Hemingway', null, 'Chol va dengiz');
        $this->legacyBook('Ernest Hemingway', null, 'Alvido qurol');
        $this->legacyBook('Orhan Pamuk', null, 'Qor');

        $this->replayTheMigrations();

        // Two names, three books — the point of the whole exercise is that
        // the two Hemingway books end up on the same row rather than
        // creating a duplicate each.
        $this->assertSame(2, Author::count());
        $this->assertSame(2, Author::where('name', 'Ernest Hemingway')->first()->books()->count());
    }

    public function test_each_book_is_pointed_at_the_row_for_its_own_author(): void
    {
        $this->rewindToLegacySchema();

        $hemingway = $this->legacyBook('Ernest Hemingway', null, 'Chol va dengiz');
        $pamuk = $this->legacyBook('Orhan Pamuk', null, 'Qor');

        $this->replayTheMigrations();

        $this->assertSame('Ernest Hemingway', Book::find($hemingway)->authorRecord->name);
        $this->assertSame('Orhan Pamuk', Book::find($pamuk)->authorRecord->name);
    }

    public function test_the_original_strings_are_left_in_place(): void
    {
        $this->rewindToLegacySchema();

        $id = $this->legacyBook('Ernest Hemingway', 'Yangi asr avlodi', 'Chol va dengiz');

        $this->replayTheMigrations();

        // Nothing is dropped: BookResource falls back to these columns for
        // any row the backfill could not match, and they are the only
        // record of what was actually typed.
        $book = Book::find($id);
        $this->assertSame('Ernest Hemingway', $book->getRawOriginal('author'));
        $this->assertSame('Yangi asr avlodi', $book->publisher);
    }

    public function test_names_are_slugged(): void
    {
        $this->rewindToLegacySchema();

        $this->legacyBook('Ernest Hemingway', 'Yangi asr avlodi', 'Chol va dengiz');

        $this->replayTheMigrations();

        $this->assertSame('ernest-hemingway', Author::first()->slug);
        $this->assertSame('yangi-asr-avlodi', Publisher::first()->slug);
    }

    public function test_two_names_that_slug_to_the_same_string_get_distinct_slugs(): void
    {
        $this->rewindToLegacySchema();

        // Real data does this: the same writer entered with and without
        // punctuation is two distinct names but one slug, and the slug
        // column is unique — without the suffix loop the migration dies
        // half-way through, on the production database.
        $this->legacyBook('Ernest Hemingway', null, 'Chol va dengiz');
        $this->legacyBook('Ernest  Hemingway!', null, 'Alvido qurol');

        $this->replayTheMigrations();

        $slugs = Author::orderBy('id')->pluck('slug')->all();

        $this->assertCount(2, array_unique($slugs));
        $this->assertContains('ernest-hemingway', $slugs);
        $this->assertContains('ernest-hemingway-2', $slugs);
    }

    public function test_a_name_with_no_sluggable_characters_still_gets_a_slug(): void
    {
        $this->rewindToLegacySchema();

        // Str::slug() returns "" for a name written entirely in a script it
        // cannot transliterate, and "" would collide with the next such
        // name on a unique column.
        $this->legacyBook('三島 由紀夫', '株式会社', 'Kinjiki');
        $this->legacyBook('川端 康成', null, 'Yukiguni');

        $this->replayTheMigrations();

        $slugs = Author::orderBy('id')->pluck('slug')->all();

        $this->assertSame(['author', 'author-2'], $slugs);
        $this->assertSame('publisher', Publisher::first()->slug);
    }

    public function test_books_with_no_publisher_are_left_unlinked(): void
    {
        $this->rewindToLegacySchema();

        $id = $this->legacyBook('Ernest Hemingway', null, 'Chol va dengiz');

        $this->replayTheMigrations();

        // publisher is nullable and always has been; an empty string must
        // not become a catalogue row called "".
        $this->assertNull(Book::find($id)->publisher_id);
        $this->assertSame(0, Publisher::count());
    }

    public function test_an_empty_publisher_string_does_not_become_a_row(): void
    {
        $this->rewindToLegacySchema();

        $id = $this->legacyBook('Ernest Hemingway', '', 'Chol va dengiz');

        $this->replayTheMigrations();

        $this->assertSame(0, Publisher::count());
        $this->assertNull(Book::find($id)->publisher_id);
    }

    public function test_publishers_are_deduplicated_and_linked_like_authors(): void
    {
        $this->rewindToLegacySchema();

        $first = $this->legacyBook('Ernest Hemingway', 'Yangi asr avlodi', 'Chol va dengiz');
        $second = $this->legacyBook('Orhan Pamuk', 'Yangi asr avlodi', 'Qor');
        $this->legacyBook('Franz Kafka', 'Akademnashr', 'Jarayon');

        $this->replayTheMigrations();

        $this->assertSame(2, Publisher::count());
        $this->assertSame(
            Book::find($first)->publisher_id,
            Book::find($second)->publisher_id,
        );
        $this->assertSame(2, Publisher::where('name', 'Yangi asr avlodi')->first()->books()->count());
    }

    public function test_deleting_a_catalogue_row_leaves_the_books_standing(): void
    {
        $this->rewindToLegacySchema();

        $id = $this->legacyBook('Ernest Hemingway', 'Yangi asr avlodi', 'Chol va dengiz');

        $this->replayTheMigrations();

        Author::first()->delete();
        Publisher::first()->delete();

        // nullOnDelete, not cascade: removing a duplicate author from the
        // catalogue must never take a translator's book with it.
        $book = Book::find($id);
        $this->assertNotNull($book);
        $this->assertNull($book->author_id);
        $this->assertNull($book->publisher_id);
        // ...and the book can still name them, from the legacy columns.
        $this->assertSame('Ernest Hemingway', $book->getRawOriginal('author'));
    }

    public function test_the_migrations_roll_back_cleanly(): void
    {
        $this->rewindToLegacySchema();

        $this->legacyBook('Ernest Hemingway', 'Yangi asr avlodi', 'Chol va dengiz');

        $this->replayTheMigrations();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('books', 'author_id'));

        // A migration that cannot be undone is a deploy that cannot be
        // undone.
        $this->rewindToLegacySchema();
        $this->assertSame(1, DB::table('books')->count());
    }
}
