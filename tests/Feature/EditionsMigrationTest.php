<?php

namespace Tests\Feature;

use App\Enums\BookRole;
use App\Models\Book;
use App\Models\Taqriz;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The 2026_09_26 migrations split every book row into a work and its one
 * edition, and move taqrizlar onto that edition. Like the 09_14 backfill,
 * this runs once against production data, so what it keeps, moves and
 * refuses is pinned here.
 *
 * DatabaseMigrations for the same reason AuthorPublisherBackfillTest gives:
 * these tests roll the schema back and forward.
 */
class EditionsMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private const FIRST = '2026_09_26_000001_create_editions_and_catalogue_tables';

    private function rewind(): void
    {
        $steps = DB::table('migrations')->where('migration', '>=', self::FIRST)->count();

        Artisan::call('migrate:rollback', ['--step' => $steps, '--force' => true]);

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('editions'));
    }

    private function replay(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    /** @return array{book: int, translator: int, taqriz: int} */
    private function legacyRows(): array
    {
        $now = now();
        $user = DB::table('users')->insertGetId(['name' => 'Ilhom', 'email' => 'ilhom@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
        $translator = DB::table('translators')->insertGetId(['user_id' => $user, 'slug' => 'ilhom', 'name' => 'Ilhom', 'years_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $author = DB::table('authors')->insertGetId(['slug' => 'pierre-boulle', 'name' => 'Pierre Boulle', 'created_at' => $now, 'updated_at' => $now]);

        $book = DB::table('books')->insertGetId([
            'translator_id' => $translator,
            'author_id' => $author,
            'role' => BookRole::Editor->value,
            'uzbek_title' => 'Maymunlar sayyorasi',
            'original_title' => 'La Planète des singes',
            'author' => 'Pierre Boulle',
            'author_image_path' => 'authors/boulle.jpg',
            'source_language' => 'Fransuz tili',
            // Free text only, as the translator form has always saved it.
            'publisher' => 'Asaxiy Books',
            'publisher_image_path' => 'publishers/asaxiy.png',
            'year' => 2023,
            'cover_path' => 'covers/maymunlar.jpg',
            'excerpt_source_language' => 'Fransuz tili',
            'excerpt_source_text' => 'Je confie ce manuscrit…',
            'excerpt_translated_text' => "Men bu qo'lyozmani…",
            'sort_order' => 3,
            'status' => 'approved',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $taqrizchiUser = DB::table('users')->insertGetId(['name' => 'Nodira', 'email' => 'nodira@example.test', 'password' => 'x', 'role' => 'taqrizchi', 'created_at' => $now, 'updated_at' => $now]);
        $taqrizchi = DB::table('taqrizchilar')->insertGetId(['user_id' => $taqrizchiUser, 'slug' => 'nodira', 'name' => 'Nodira', 'created_at' => $now, 'updated_at' => $now]);
        $taqriz = DB::table('taqrizlar')->insertGetId(['book_id' => $book, 'taqrizchi_id' => $taqrizchi, 'body' => 'Zo\'r.', 'status' => 'approved', 'created_at' => $now, 'updated_at' => $now]);

        return compact('book', 'translator', 'taqriz');
    }

    public function test_each_book_becomes_a_work_with_one_edition_carrying_its_printing(): void
    {
        $this->rewind();
        $ids = $this->legacyRows();
        $this->replay();

        $book = Book::with(['authors', 'editions.publisher', 'editions.translators'])->find($ids['book']);
        $edition = $book->editions->sole();

        $this->assertSame('Maymunlar sayyorasi', $book->uzbek_title);
        $this->assertSame('Pierre Boulle', $book->author_names);
        $this->assertSame(2023, $edition->year);
        $this->assertSame('covers/maymunlar.jpg', $edition->cover_path);
        $this->assertSame(3, $edition->sort_order);
        $this->assertSame("Men bu qo'lyozmani…", $edition->excerpt_translated_text);

        // The free-text publisher becomes a catalogue row, with the image
        // that used to ride along on the book as its logo.
        $this->assertSame('Asaxiy Books', $edition->publisher->name);
        $this->assertSame('publishers/asaxiy.png', $edition->publisher->logo_path);
        $this->assertSame('authors/boulle.jpg', $book->authors->first()->portrait_path);

        // The translator keeps their credit and their role on it.
        $this->assertSame($ids['translator'], $edition->translators->sole()->id);
        $this->assertSame(BookRole::Editor, $edition->translators->sole()->pivot->role);
    }

    public function test_a_taqriz_moves_to_the_edition_of_its_book(): void
    {
        $this->rewind();
        $ids = $this->legacyRows();
        $this->replay();

        $taqriz = Taqriz::find($ids['taqriz']);

        $this->assertSame($ids['book'], $taqriz->edition->book_id);
        $this->assertSame($ids['book'], $taqriz->book->id);
    }

    public function test_rolling_back_folds_the_edition_back_into_the_book(): void
    {
        $this->rewind();
        $ids = $this->legacyRows();
        $this->replay();
        $this->rewind();

        $row = DB::table('books')->find($ids['book']);

        $this->assertSame($ids['translator'], $row->translator_id);
        $this->assertSame('editor', $row->role);
        $this->assertSame('Pierre Boulle', $row->author);
        $this->assertSame('Asaxiy Books', $row->publisher);
        $this->assertSame(2023, (int) $row->year);
        $this->assertSame('covers/maymunlar.jpg', $row->cover_path);
        $this->assertSame($ids['book'], DB::table('taqrizlar')->where('id', $ids['taqriz'])->value('book_id'));
    }

    public function test_rolling_back_refuses_once_a_book_has_two_editions(): void
    {
        $this->rewind();
        $ids = $this->legacyRows();
        $this->replay();

        $second = Book::find($ids['book'])->editions()->create(['year' => 2025]);

        // Folding two editions into one row would silently delete one of
        // them; the operator decides which, not the migration.
        try {
            Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
            $this->fail('The rollback went ahead with two editions of one book.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 book(s) with more than one edition', $e->getMessage());
        }

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('editions'), 'nothing was dropped');

        // DatabaseMigrations rolls everything back after the test.
        $second->delete();
    }
}
