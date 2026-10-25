<?php

namespace Tests\Feature\Api;

use App\Enums\BookRole;
use App\Enums\ModerationStatus;
use App\Models\Author;
use App\Models\Book;
use App\Models\Category;
use App\Models\Edition;
use App\Models\Publisher;
use App\Models\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * /api/sync/* is how the Kitoblarim bot and this catalogue stay level: the
 * bot pushes what its moderators approved, and pulls what changed here.
 * The rules being pinned are in docs/DATA-MODEL.md, "Syncing with the
 * Kitoblarim bot".
 */
class CatalogueSyncApiTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    private const TOKEN = 'bot-sync-secret';

    private const ISBN = '9789943231986';

    protected function setUp(): void
    {
        parent::setUp();

        config(['catalogue.sync_token' => self::TOKEN]);
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_sync_is_off_until_a_token_is_configured(): void
    {
        config(['catalogue.sync_token' => null]);

        // Not 401 and not an open door, whatever the caller sends.
        $this->getJson('/api/sync/editions')
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Catalogue sync is not configured']);

        $this->withToken('anything')->putJson('/api/sync/editions/'.self::ISBN, $this->body())
            ->assertStatus(503);

        $this->assertSame(0, Edition::count());
    }

    public function test_a_missing_or_wrong_token_is_refused_and_the_right_one_let_in(): void
    {
        $this->getJson('/api/sync/editions')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/sync/editions')->assertUnauthorized();
        $this->withToken(self::TOKEN.'x')->getJson('/api/sync/editions')->assertUnauthorized();

        $this->putJson('/api/sync/editions/'.self::ISBN, $this->body())->assertUnauthorized();
        $this->withToken('wrong')->putJson('/api/sync/editions/'.self::ISBN, $this->body())->assertUnauthorized();
        $this->assertSame(0, Edition::count());

        $this->withToken(self::TOKEN)->getJson('/api/sync/editions')
            ->assertOk()
            ->assertExactJson(['items' => [], 'next' => null]);
    }

    public function test_requests_without_the_token_do_not_use_up_the_bots_rate_limit(): void
    {
        // The bot's bucket is one for the whole sync (AppServiceProvider), so
        // if strangers counted against it they could lock the bot out.
        for ($i = 0; $i < 600; $i++) {
            $status = $this->withToken('wrong')->getJson('/api/sync/editions')->status();
            $this->assertContains($status, [401, 429]);
        }

        $this->withToken(self::TOKEN)->getJson('/api/sync/editions')->assertOk();
    }

    public function test_the_sync_routes_are_not_part_of_the_public_read_api(): void
    {
        // The read group has no such path, and cannot be written to.
        $this->getJson('/api/editions')->assertNotFound();
        $this->putJson('/api/books/1', [])->assertStatus(405);
    }

    // ------------------------------------------------------------------
    // Push: a new ISBN
    // ------------------------------------------------------------------

    public function test_a_new_isbn_creates_the_book_authors_publisher_and_translator_shells(): void
    {
        $this->push(self::ISBN, ['translators' => ['Ilhom Salimov']])
            ->assertOk()
            ->assertJsonPath('outcome', 'created')
            ->assertJsonStructure(['outcome', 'bookId', 'editionId', 'updatedAt']);

        $edition = Edition::with(['book.authors', 'publisher', 'translators'])->where('isbn', self::ISBN)->firstOrFail();
        $book = $edition->book;

        $this->assertSame(ModerationStatus::Approved, $edition->status);
        $this->assertSame(ModerationStatus::Approved, $book->status);
        $this->assertSame('Mehrobdan chayon', $book->uzbek_title);
        $this->assertNull($edition->title, 'an edition titled like its book carries no title of its own');
        $this->assertSame("O'zbek tili", $edition->language);
        $this->assertSame(2019, $edition->year);
        $this->assertSame(300, $edition->pages);
        $this->assertSame('Tarixiy roman', $edition->description);
        $this->assertSame('Sharq', $edition->publisher->name);
        $this->assertSame(['Abdulla Qodiriy', 'Oybek'], $book->authors->pluck('name')->all());
        $this->assertSame([0, 1], $book->authors->map(fn ($author) => $author->pivot->position)->all());

        $translator = Translator::where('name', 'Ilhom Salimov')->firstOrFail();
        $this->assertNull($translator->user_id, 'a translator named by the bot is a shell with no login');
        $this->assertSame('ilhom-salimov', $translator->slug);
        $this->assertSame($translator->id, $edition->translators->sole()->id);
        $this->assertSame(BookRole::Translator, $edition->translators->sole()->pivot->role);

        $this->assertSame('bot', $edition->sync_origin);
        $this->assertNotNull($edition->synced_at);
        $this->assertSame(40, strlen($edition->sync_hash));
    }

    public function test_a_year_no_printing_can_carry_arrives_as_no_year_and_never_replaces_a_real_one(): void
    {
        // 978-9943-… once came through as the year 9943.
        $this->push(self::ISBN, ['year' => 9943])->assertOk()->assertJsonPath('outcome', 'created');
        $this->assertNull(Edition::firstOrFail()->year);

        $this->push(self::ISBN, ['year' => 2024, 'updatedAt' => '2026-04-01T00:00:00Z'])->assertOk();
        $this->assertSame(2024, Edition::firstOrFail()->year);

        $this->push(self::ISBN, ['year' => 1500, 'updatedAt' => '2026-05-01T00:00:00Z'])->assertOk();
        $this->assertSame(2024, Edition::firstOrFail()->year);
    }

    public function test_the_response_reports_the_stored_edition_and_its_updated_at(): void
    {
        $response = $this->push(self::ISBN)->assertOk();

        $edition = Edition::where('isbn', self::ISBN)->firstOrFail();

        $response->assertJsonPath('bookId', $edition->book_id)->assertJsonPath('editionId', $edition->id);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $response->json('updatedAt'));
        $this->assertSame($edition->updated_at->utc()->format('Y-m-d\TH:i:s\Z'), $response->json('updatedAt'));
    }

    public function test_strings_are_cleaned_and_apostrophes_normalised(): void
    {
        $this->push(self::ISBN, [
            'title' => "  O‘tkan   kunlar \u{200B}",
            'authors' => ['Abdulla  Qodiriy'],
            'publisher' => 'G‘afur G‘ulom',
            'description' => "Oʻzbek  romani\n  haqida",
        ])->assertOk();

        $edition = Edition::with(['book', 'publisher'])->firstOrFail();

        $this->assertSame("O'tkan kunlar", $edition->book->uzbek_title);
        $this->assertSame("G'afur G'ulom", $edition->publisher->name);
        $this->assertSame("O'zbek romani\nhaqida", $edition->description, 'a description keeps its line breaks');
    }

    public function test_cyrillic_is_made_latin_for_uzbek_editions_only(): void
    {
        $this->push(self::ISBN, [
            'title' => 'Меҳробдан чаён',
            'authors' => ['Абдулла Қодирий'],
            'publisher' => 'Шарқ',
            'language' => 'uz',
        ])->assertOk();

        $uzbek = Edition::with(['book.authors', 'publisher'])->where('isbn', self::ISBN)->firstOrFail();
        $this->assertSame('Mehrobdan chayon', $uzbek->book->uzbek_title);
        $this->assertSame(['Abdulla Qodiriy'], $uzbek->book->authors->pluck('name')->all());
        $this->assertSame('Sharq', $uzbek->publisher->name);

        $this->push('9785170000011', [
            'title' => 'Война и мир',
            'authors' => ['Лев Толстой'],
            'publisher' => 'Эксмо',
            'language' => 'ru',
        ])->assertOk();

        $russian = Edition::with(['book.authors', 'publisher'])->where('isbn', '9785170000011')->firstOrFail();
        $this->assertSame('Война и мир', $russian->book->uzbek_title);
        $this->assertSame(['Лев Толстой'], $russian->book->authors->pluck('name')->all());
        $this->assertSame('Эксмо', $russian->publisher->name);
        $this->assertSame('Rus tili', $russian->language);
    }

    public function test_language_codes_become_the_labels_the_catalogue_stores(): void
    {
        $this->push('9780000000001', ['title' => 'A', 'language' => 'uz']);
        $this->push('9780000000002', ['title' => 'B', 'language' => 'RU']);
        $this->push('9780000000003', ['title' => 'C', 'language' => 'en']);
        $this->push('9780000000004', ['title' => 'D', 'language' => 'Qirg‘iz tili']);
        $this->push('9780000000005', ['title' => 'E', 'language' => null]);

        $this->assertSame(
            ["O'zbek tili", 'Rus tili', 'Ingliz tili', "Qirg'iz tili", null],
            Edition::orderBy('id')->pluck('language')->all(),
        );
    }

    public function test_an_isbn_with_hyphens_is_stored_as_digits(): void
    {
        $this->push('978-9943-23-198-6')->assertOk()->assertJsonPath('outcome', 'created');

        $this->assertSame(self::ISBN, Edition::sole()->isbn);

        // And the same book under another spelling of its ISBN is the same edition.
        $this->push(self::ISBN)->assertOk();
        $this->assertSame(1, Edition::count());
    }

    public function test_an_invalid_isbn_is_a_422_and_creates_nothing(): void
    {
        foreach (['12345', 'abcdefghijklm', '97899432319861', '0-306-40615-2', '978994323198X'] as $isbn) {
            $this->push($isbn)->assertStatus(422)->assertJsonValidationErrors(['isbn']);
        }

        $this->assertSame(0, Edition::count());
        $this->assertSame(0, Book::count());
    }

    public function test_the_body_is_validated(): void
    {
        $this->push(self::ISBN, ['title' => null])->assertStatus(422)->assertJsonValidationErrors(['title']);
        $this->push(self::ISBN, ['updatedAt' => '2026-13-45'])->assertStatus(422)->assertJsonValidationErrors(['updatedAt']);
        $this->push(self::ISBN, ['pages' => 0])->assertStatus(422)->assertJsonValidationErrors(['pages']);
        $this->push(self::ISBN, ['authors' => 'Oybek'])->assertStatus(422)->assertJsonValidationErrors(['authors']);

        $this->assertSame(0, Edition::count());
    }

    public function test_a_new_isbn_joins_an_existing_book_whose_author_is_spelled_in_cyrillic(): void
    {
        $author = Author::create(['name' => 'Абдулла Қодирий', 'slug' => 'abdulla-qodiriy']);
        $book = Book::create(['uzbek_title' => 'Mehrobdan chayon']);
        $book->authors()->attach($author->id, ['position' => 0]);
        $this->edition($book, ['isbn' => '9780000000099', 'publisher_id' => $this->publisher('Eski nashr')->id]);

        $this->push(self::ISBN, ['title' => 'Mehrobdan chayon', 'authors' => ['Abdulla Qodiriy']])
            ->assertOk()
            ->assertJsonPath('outcome', 'created')
            ->assertJsonPath('bookId', $book->id);

        $this->assertSame(1, Book::count());
        $this->assertSame(1, Author::count());
        $this->assertSame(2, $book->editions()->count());
        $this->assertSame($book->id, Edition::where('isbn', self::ISBN)->firstOrFail()->book_id);
    }

    public function test_a_cyrillic_push_for_an_uzbek_edition_finds_the_latin_book(): void
    {
        $book = $this->catalogueBook(['uzbek_title' => 'Mehrobdan chayon'], authors: [$this->author('Abdulla Qodiriy')]);

        $this->push(self::ISBN, [
            'title' => 'Меҳробдан чаён',
            'authors' => ['Абдулла Қодирий'],
            'language' => 'uz',
        ])->assertOk()->assertJsonPath('bookId', $book->id);

        $this->assertSame(1, Book::count());
        $this->assertSame(1, Author::count());
        $this->assertNull(Edition::where('isbn', self::ISBN)->firstOrFail()->title);
    }

    public function test_a_different_title_by_the_same_author_is_a_different_book(): void
    {
        $this->catalogueBook(['uzbek_title' => "O'tkan kunlar"], authors: [$this->author('Abdulla Qodiriy')]);

        $this->push(self::ISBN, ['title' => 'Mehrobdan chayon', 'authors' => ['Abdulla Qodiriy']])->assertOk();

        $this->assertSame(2, Book::count());
        $this->assertSame(1, Author::count());
    }

    public function test_publishers_and_translators_are_matched_by_key_not_duplicated(): void
    {
        $publisher = Publisher::create(['name' => 'Hilol nashr', 'slug' => 'hilol-nashr']);
        $translator = Translator::create(['name' => 'Ilhom Salimov', 'slug' => 'ilhom-salimov']);

        $this->push(self::ISBN, ['publisher' => 'Ҳилол-нашр', 'translators' => ['Илҳом Салимов'], 'language' => 'uz'])->assertOk();

        $edition = Edition::with(['translators'])->firstOrFail();
        $this->assertSame($publisher->id, $edition->publisher_id);
        $this->assertSame(1, Publisher::count());
        $this->assertSame(1, Translator::count());
        $this->assertSame($translator->id, $edition->translators->sole()->id);
    }

    public function test_the_publisher_placeholder_from_asaxiy_is_no_publisher(): void
    {
        $this->push(self::ISBN, ['publisher' => 'Boshqa nashriyotlar'])->assertOk();

        $this->assertNull(Edition::sole()->publisher_id);
        $this->assertSame(0, Publisher::count());
    }

    // ------------------------------------------------------------------
    // Push: an ISBN we already have
    // ------------------------------------------------------------------

    public function test_a_newer_push_overwrites_with_what_it_has_and_never_erases(): void
    {
        $this->push(self::ISBN, ['translators' => ['Ilhom Salimov']])->assertJsonPath('outcome', 'created');

        $edition = Edition::firstOrFail();
        $edition->book->categories()->attach(Category::create(['slug' => 'roman', 'name' => 'Roman'])->id, ['is_primary' => true]);
        $edition->update(['cover_path' => 'covers/chayon.webp']);
        $this->setUpdatedAt($edition, '2026-01-01 10:00:00');

        $response = $this->push(self::ISBN, [
            'title' => 'Mehrobdan chayon (2-nashr)',
            'authors' => ['Boshqa Yozuvchi'],
            'translators' => ['Dilnoza Karimova'],
            'publisher' => null,
            'language' => 'ru',
            'year' => 2021,
            'pages' => 350,
            'description' => null,
            'updatedAt' => '2026-06-01T00:00:00Z',
        ])->assertOk()->assertJsonPath('outcome', 'updated');

        $edition = Edition::with(['book.authors', 'publisher', 'translators'])->firstOrFail();

        $this->assertSame('Mehrobdan chayon (2-nashr)', $edition->title);
        $this->assertSame('Rus tili', $edition->language);
        $this->assertSame(2021, $edition->year);
        $this->assertSame(350, $edition->pages);
        // Blank incoming values leave ours alone.
        $this->assertSame('Tarixiy roman', $edition->description);
        $this->assertSame('Sharq', $edition->publisher->name);
        // The credit list is replaced, not added to; the old shell stays as a profile.
        $this->assertSame(['Dilnoza Karimova'], $edition->translators->pluck('name')->all());
        $this->assertSame(2, Translator::count());
        // The book is not the bot's to rename or re-credit.
        $this->assertSame('Mehrobdan chayon', $edition->book->uzbek_title);
        $this->assertSame(['Abdulla Qodiriy', 'Oybek'], $edition->book->authors->pluck('name')->all());
        // Nor are the cover, the shelves or the status.
        $this->assertSame('covers/chayon.webp', $edition->cover_path);
        $this->assertSame(['Roman'], $edition->book->categories->pluck('name')->all());
        $this->assertSame(ModerationStatus::Approved, $edition->status);
        $this->assertTrue($edition->updated_at->greaterThan('2026-01-01 10:00:00'));
        $this->assertSame($edition->updated_at->utc()->format('Y-m-d\TH:i:s\Z'), $response->json('updatedAt'));
    }

    public function test_a_title_matching_the_book_clears_the_editions_own_title(): void
    {
        $this->push(self::ISBN)->assertOk();
        $edition = Edition::firstOrFail();
        $edition->update(['title' => 'Old printed title']);
        $this->setUpdatedAt($edition, '2026-01-01 10:00:00');

        $this->push(self::ISBN, ['title' => 'Mehrobdan chayon', 'updatedAt' => '2026-06-01T00:00:00Z'])
            ->assertJsonPath('outcome', 'updated');

        $this->assertNull(Edition::firstOrFail()->title);
    }

    public function test_a_first_meeting_push_only_fills_blanks_however_new_it_is(): void
    {
        // The bot has never synced this book, so its timestamp says nothing
        // about which side is right: nothing here may be overwritten.
        $this->push(self::ISBN, ['publisher' => null, 'year' => null, 'pages' => 250, 'description' => null, 'translators' => []])
            ->assertJsonPath('outcome', 'created');

        $edition = Edition::firstOrFail();
        $edition->update(['language' => 'Rus tili']);
        $this->setUpdatedAt($edition, '2026-06-01 00:00:00');

        $this->push(self::ISBN, [
            'title' => 'Boshqa nom',
            'language' => 'en',
            'publisher' => 'Sharq',
            'year' => 2025,
            'pages' => 400,
            'updatedAt' => '2026-10-11T00:00:00Z',
            'fillOnly' => true,
        ])->assertOk()->assertJsonPath('outcome', 'enriched');

        $edition = Edition::with('publisher')->firstOrFail();
        $this->assertSame('Sharq', $edition->publisher->name);
        $this->assertSame(2025, $edition->year);
        $this->assertSame(250, $edition->pages);
        $this->assertSame('Rus tili', $edition->language);
        $this->assertNull($edition->title);
    }

    public function test_an_older_push_only_fills_blanks(): void
    {
        $this->push(self::ISBN, ['publisher' => null, 'year' => null, 'pages' => null, 'description' => null, 'translators' => []])
            ->assertJsonPath('outcome', 'created');

        $edition = Edition::firstOrFail();
        $edition->update(['language' => 'Rus tili']);
        $this->setUpdatedAt($edition, '2026-06-01 00:00:00');

        $this->push(self::ISBN, [
            'title' => 'Boshqa nom',
            'language' => 'en',
            'publisher' => 'Sharq',
            'translators' => ['Ilhom Salimov'],
            'year' => 2025,
            'pages' => 400,
            'description' => 'Yangi tavsif',
            'updatedAt' => '2026-01-01T00:00:00Z',
        ])->assertOk()->assertJsonPath('outcome', 'enriched');

        $edition = Edition::with(['publisher', 'translators'])->firstOrFail();
        $this->assertSame('Sharq', $edition->publisher->name);
        $this->assertSame(2025, $edition->year);
        $this->assertSame(400, $edition->pages);
        $this->assertSame('Yangi tavsif', $edition->description);
        $this->assertSame(['Ilhom Salimov'], $edition->translators->pluck('name')->all());
        // Filled blanks only: what we already had stays, and an older title never renames.
        $this->assertSame('Rus tili', $edition->language);
        $this->assertNull($edition->title);

        // And a second, equally old push has nothing left to add.
        $this->push(self::ISBN, [
            'title' => 'Boshqa nom',
            'language' => 'en',
            'publisher' => 'Boshqa nashr',
            'translators' => ['Dilnoza Karimova'],
            'year' => 1999,
            'pages' => 1,
            'description' => 'Eski tavsif',
            'updatedAt' => '2026-01-01T00:00:00Z',
        ])->assertOk()->assertJsonPath('outcome', 'unchanged');

        $this->assertSame(2025, Edition::firstOrFail()->year);
        $this->assertSame(0, Translator::where('name', 'Dilnoza Karimova')->count());
        $this->assertSame(0, Publisher::where('name', 'Boshqa nashr')->count(), 'a publisher is only created when it will be used');
    }

    public function test_a_push_that_changes_nothing_reports_unchanged_and_keeps_updated_at(): void
    {
        $this->push(self::ISBN)->assertJsonPath('outcome', 'created');
        $edition = Edition::firstOrFail();
        $this->setUpdatedAt($edition, '2026-01-01 10:00:00');

        $this->push(self::ISBN, ['updatedAt' => '2026-06-01T00:00:00Z'])
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('updatedAt', '2026-01-01T10:00:00Z');
    }

    public function test_a_pending_edition_the_bot_approved_becomes_approved(): void
    {
        $book = $this->catalogueBook(
            ['uzbek_title' => 'Mehrobdan chayon'],
            ['isbn' => self::ISBN, 'status' => ModerationStatus::Pending, 'pages' => null],
            [$this->author('Abdulla Qodiriy')],
        );
        $edition = $book->editions()->sole();
        $this->setUpdatedAt($edition, '2026-06-01 00:00:00');

        $this->push(self::ISBN, ['updatedAt' => '2026-01-01T00:00:00Z'])
            ->assertOk()
            ->assertJsonPath('outcome', 'enriched')
            ->assertJsonPath('editionId', $edition->id);

        $edition->refresh();
        $this->assertSame(ModerationStatus::Approved, $edition->status);
        $this->assertSame(300, $edition->pages);
        $this->assertSame(1, Edition::count());
    }

    public function test_a_rejected_edition_stays_rejected_and_untouched(): void
    {
        $this->catalogueBook(edition: ['isbn' => self::ISBN, 'status' => ModerationStatus::Rejected, 'pages' => 12]);

        $this->push(self::ISBN, ['pages' => 999, 'updatedAt' => '2099-01-01T00:00:00Z'])
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged');

        $edition = Edition::sole();
        $this->assertSame(ModerationStatus::Rejected, $edition->status);
        $this->assertSame(12, $edition->pages);
        $this->assertNull($edition->sync_hash);
    }

    public function test_a_push_pings_the_public_site_once_for_the_book(): void
    {
        config(['seo.revalidate.url' => 'http://site.test/api/revalidate', 'seo.revalidate.secret' => 'secret']);
        Http::fake();

        $bookId = $this->push(self::ISBN, ['translators' => ['Ilhom Salimov', 'Dilnoza Karimova']])->json('bookId');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['type'] === 'book' && $request['id'] === (string) $bookId);
    }

    // ------------------------------------------------------------------
    // Genre → shelf
    // ------------------------------------------------------------------

    public function test_a_genre_puts_a_book_on_no_shelf_on_its_shelf_as_the_primary_one(): void
    {
        $fiction = Category::create(['slug' => 'badiiy-adabiyot', 'name' => 'Badiiy adabiyot']);
        Category::create(['slug' => 'diniy-adabiyot', 'name' => 'Diniy adabiyot']);

        $bookId = $this->push(self::ISBN, ['genre' => 'Badiiy adabiyotlar'])->assertJsonPath('outcome', 'created')->json('bookId');

        $this->assertSame([[$fiction->id, 1]], $this->shelvesOf($bookId));

        // Not part of what the loop guard hashes: the feed still has nothing.
        $this->pull()->assertExactJson(['items' => [], 'next' => null]);

        // Once a book is on a shelf, a later genre never moves it.
        $this->push(self::ISBN, ['genre' => 'Diniy adabiyotlar', 'updatedAt' => '2099-01-01T00:00:00Z']);
        $this->assertSame([[$fiction->id, 1]], $this->shelvesOf($bookId));
    }

    public function test_a_genre_named_like_a_shelf_needs_no_alias_and_an_unknown_one_is_ignored(): void
    {
        $uzbek = Category::create(['slug' => 'ozbek-adabiyoti', 'name' => "O'zbek adabiyoti"]);

        $named = $this->push(self::ISBN, ['genre' => 'OʻZBEK  adabiyoti'])->json('bookId');
        $unknown = $this->push('9789943231979', ['title' => 'Boshqa kitob', 'genre' => 'Ilmiy-ommabop'])->assertOk()->json('bookId');
        $none = $this->push('9789943231962', ['title' => 'Uchinchi kitob', 'genre' => null])->assertOk()->json('bookId');

        $this->assertSame([[$uzbek->id, 1]], $this->shelvesOf($named));
        $this->assertSame([], $this->shelvesOf($unknown));
        $this->assertSame([], $this->shelvesOf($none));
    }

    public function test_a_book_already_on_a_shelf_or_a_rejected_edition_is_left_alone(): void
    {
        $fiction = Category::create(['slug' => 'badiiy-adabiyot', 'name' => 'Badiiy adabiyot']);
        $history = Category::create(['slug' => 'tarix', 'name' => 'Tarix']);

        $shelved = $this->catalogueBook(['uzbek_title' => 'Mehrobdan chayon'], ['isbn' => self::ISBN], [$this->author('Abdulla Qodiriy')]);
        $shelved->categories()->attach($history->id, ['is_primary' => false]);
        $rejected = $this->catalogueBook(['uzbek_title' => 'Rad etilgan'], ['isbn' => '9789943231979', 'status' => ModerationStatus::Rejected]);

        $this->push(self::ISBN, ['genre' => 'Badiiy adabiyotlar'])->assertOk();
        $this->push('9789943231979', ['title' => 'Rad etilgan', 'genre' => 'Badiiy adabiyotlar'])->assertOk();

        $this->assertSame([[$history->id, 0]], $this->shelvesOf($shelved->id));
        $this->assertSame([], $this->shelvesOf($rejected->id));
        $this->assertSame(0, DB::table('book_category')->where('category_id', $fiction->id)->count());
    }

    public function test_a_push_that_only_shelves_the_book_still_pings_the_site(): void
    {
        Category::create(['slug' => 'tarix', 'name' => 'Tarix']);
        $bookId = $this->push(self::ISBN)->json('bookId');

        config(['seo.revalidate.url' => 'http://site.test/api/revalidate', 'seo.revalidate.secret' => 'secret']);
        Http::fake();

        $this->push(self::ISBN, ['genre' => 'Tarix va Siyosat'])
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonMissingPath('shelved');

        $this->assertCount(1, $this->shelvesOf($bookId));
        Http::assertSentCount(1);
    }

    // ------------------------------------------------------------------
    // Pull
    // ------------------------------------------------------------------

    public function test_the_feed_carries_the_projection_genre_and_an_absolute_cover_url(): void
    {
        $translator = $this->translatorWithAccount('ilhom', 'Ilhom Salimov');
        $book = $this->catalogueBook(
            ['uzbek_title' => 'Mehrobdan chayon'],
            [
                'isbn' => self::ISBN,
                'publisher_id' => $this->publisher('Sharq')->id,
                'language' => "O'zbek tili",
                'year' => 2019,
                'pages' => 300,
                'description' => 'Tarixiy roman',
                'cover_path' => 'covers/chayon.webp',
            ],
            [$this->author('Abdulla Qodiriy'), $this->author('Oybek')],
            [$translator],
        );
        $book->categories()->attach(Category::create(['slug' => 'roman', 'name' => 'Roman'])->id, ['is_primary' => true]);
        $book->categories()->attach(Category::create(['slug' => 'top-100', 'name' => 'TOP-100'])->id);
        $this->setUpdatedAt($book->editions()->sole(), '2026-03-01 10:00:00');

        $this->pull()->assertOk()->assertExactJson([
            'items' => [[
                'isbn' => self::ISBN,
                'title' => 'Mehrobdan chayon',
                'authors' => ['Abdulla Qodiriy', 'Oybek'],
                'translators' => ['Ilhom Salimov'],
                'publisher' => 'Sharq',
                'language' => "O'zbek tili",
                'year' => 2019,
                'pages' => 300,
                'description' => 'Tarixiy roman',
                'genre' => 'Roman',
                'coverUrl' => rtrim(config('app.url'), '/').'/storage/covers/chayon.webp',
                'updatedAt' => '2026-03-01T10:00:00Z',
            ]],
            'next' => null,
        ]);
    }

    public function test_the_feed_uses_the_editions_own_title_and_nulls_what_is_unknown(): void
    {
        $this->catalogueBook(['uzbek_title' => 'Chol va dengiz'], ['isbn' => self::ISBN, 'title' => 'Chol va dengiz (qayta nashr)', 'year' => null], authors: []);

        $this->pull()
            ->assertJsonPath('items.0.title', 'Chol va dengiz (qayta nashr)')
            ->assertJsonPath('items.0.authors', [])
            ->assertJsonPath('items.0.translators', [])
            ->assertJsonPath('items.0.publisher', null)
            ->assertJsonPath('items.0.year', null)
            ->assertJsonPath('items.0.genre', null)
            ->assertJsonPath('items.0.coverUrl', null);
    }

    public function test_the_feed_leaves_out_pending_rejected_and_isbnless_editions_and_unapproved_books(): void
    {
        $this->catalogueBook(['uzbek_title' => 'Approved'], ['isbn' => '9780000000001']);
        $this->catalogueBook(['uzbek_title' => 'Pending edition'], ['isbn' => '9780000000002', 'status' => ModerationStatus::Pending]);
        $this->catalogueBook(['uzbek_title' => 'Rejected edition'], ['isbn' => '9780000000003', 'status' => ModerationStatus::Rejected]);
        $this->catalogueBook(['uzbek_title' => 'No ISBN'], ['isbn' => null]);
        $this->catalogueBook(['uzbek_title' => 'Pending book', 'status' => ModerationStatus::Pending], ['isbn' => '9780000000004']);

        $this->pull()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.isbn', '9780000000001')
            ->assertJsonPath('items.0.title', 'Approved');
    }

    public function test_the_feed_pages_by_updated_at_then_id_without_skipping_or_repeating_ties(): void
    {
        // Three rows share 10:01, so the page boundary falls inside a tie.
        $times = ['10:00:00', '10:01:00', '10:01:00', '10:01:00', '10:02:00'];
        $isbns = [];

        foreach ($times as $i => $time) {
            $isbns[] = $isbn = '97800000000'.($i + 10);
            $book = $this->catalogueBook(['uzbek_title' => "Kitob {$i}"], ['isbn' => $isbn]);
            $this->setUpdatedAt($book->editions()->sole(), "2026-01-01 {$time}");
        }

        $first = $this->pull('limit=2')->assertOk();
        $first->assertJsonCount(2, 'items')->assertJsonPath('items.0.isbn', $isbns[0])->assertJsonPath('items.1.isbn', $isbns[1]);
        $this->assertSame(
            ['since' => '2026-01-01T10:01:00Z', 'after' => Edition::where('isbn', $isbns[1])->value('id')],
            $first->json('next'),
        );

        // Following `next` page by page yields every row once, in order.
        $seen = [];
        $pages = 0;
        $query = 'limit=2';
        do {
            $page = $this->pull($query)->assertOk()->json();
            array_push($seen, ...array_column($page['items'], 'isbn'));
            $pages++;

            if ($page['next'] !== null) {
                $query = 'limit=2&since='.urlencode($page['next']['since']).'&after='.$page['next']['after'];
            }
        } while ($page['next'] !== null && $pages < 10);

        $this->assertSame($isbns, $seen);
        $this->assertSame(3, $pages);
        $this->assertCount(1, $page['items'], 'the last page is the short one');

        // A page that ends exactly at the end still says "more"; the next one is empty and final.
        $lastId = Edition::where('isbn', $isbns[4])->value('id');
        $this->pull('limit=5')->assertJsonCount(5, 'items')->assertJsonPath('next.after', $lastId);
        $this->pull("limit=5&since=2026-01-01T10:02:00Z&after={$lastId}")->assertExactJson(['items' => [], 'next' => null]);
    }

    public function test_since_accepts_an_offset_whose_plus_sign_was_not_escaped(): void
    {
        $book = $this->catalogueBook(['uzbek_title' => 'Old'], ['isbn' => '9780000000001']);
        $this->setUpdatedAt($book->editions()->sole(), '2026-01-01 10:00:00');
        $book = $this->catalogueBook(['uzbek_title' => 'New'], ['isbn' => '9780000000002']);
        $this->setUpdatedAt($book->editions()->sole(), '2026-01-01 12:00:00');

        // 13:00 at +02:00 is 11:00 UTC: after "Old", before "New".
        $this->pull('since=2026-01-01T13:00:00+02:00')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.isbn', '9780000000002');
    }

    public function test_the_feed_limit_is_clamped_and_the_query_validated(): void
    {
        $this->catalogueBook(['uzbek_title' => 'One'], ['isbn' => '9780000000001']);

        $this->pull('limit=100000')->assertOk()->assertJsonCount(1, 'items');
        $this->pull('limit=0')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('next.after', Edition::sole()->id);
        $this->pull('since=2026-13-45')->assertStatus(422)->assertJsonValidationErrors(['since']);
        $this->pull('after=-1')->assertStatus(422)->assertJsonValidationErrors(['after']);
    }

    public function test_what_the_bot_just_pushed_is_not_handed_back_until_somebody_edits_it(): void
    {
        $this->push(self::ISBN, ['translators' => ['Ilhom Salimov']])->assertJsonPath('outcome', 'created');

        $this->pull()->assertExactJson(['items' => [], 'next' => null]);

        // An edit in the admin panel changes the projection, so it comes back.
        Edition::firstOrFail()->update(['pages' => 999]);

        $this->pull()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.isbn', self::ISBN)
            ->assertJsonPath('items.0.pages', 999);

        // The bot pushing the corrected copy back settles it again.
        $this->push(self::ISBN, ['translators' => ['Ilhom Salimov'], 'pages' => 999, 'updatedAt' => now()->addMinute()->toIso8601String()])
            ->assertJsonPath('outcome', 'unchanged');

        $this->pull()->assertExactJson(['items' => [], 'next' => null]);
    }

    public function test_an_edit_that_leaves_the_synced_fields_alone_does_not_bring_it_back(): void
    {
        $this->push(self::ISBN)->assertOk();

        // The cover is not part of what the bot shares.
        Edition::firstOrFail()->update(['cover_path' => 'covers/new.webp']);

        $this->pull()->assertJsonCount(0, 'items');
    }

    public function test_a_page_of_only_hidden_rows_still_says_where_to_go_next(): void
    {
        $this->push(self::ISBN)->assertOk();
        $this->push('9789943231672', ['title' => 'Ikkinchi kitob', 'authors' => ['Oybek']])->assertOk();

        $page = $this->pull('limit=2')->assertOk()->assertJsonCount(0, 'items')->json();

        $this->assertNotNull($page['next']);
        $this->assertSame(Edition::where('isbn', '9789943231672')->value('id'), $page['next']['after']);

        $this->pull('limit=3')->assertExactJson(['items' => [], 'next' => null]);
    }

    public function test_when_this_side_won_the_push_the_bot_still_gets_our_version(): void
    {
        $this->push(self::ISBN)->assertJsonPath('outcome', 'created');
        Edition::firstOrFail()->update(['pages' => 321]);

        // The bot's copy is older, so ours stays. The row must not be
        // hidden from the feed: the bot has never seen pages = 321.
        $this->push(self::ISBN, ['pages' => 300, 'updatedAt' => '2020-01-01T00:00:00Z'])
            ->assertJsonPath('outcome', 'unchanged');

        $this->pull()->assertJsonCount(1, 'items')->assertJsonPath('items.0.pages', 321);
    }

    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function body(): array
    {
        return [
            'title' => 'Mehrobdan chayon',
            'authors' => ['Abdulla Qodiriy', 'Oybek'],
            'translators' => [],
            'publisher' => 'Sharq',
            'language' => 'uz',
            'year' => 2019,
            'pages' => 300,
            'description' => 'Tarixiy roman',
            'updatedAt' => '2026-03-01T10:00:00Z',
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    private function push(string $isbn, array $overrides = []): TestResponse
    {
        return $this->withToken(self::TOKEN)->putJson("/api/sync/editions/{$isbn}", [...$this->body(), ...$overrides]);
    }

    private function pull(string $query = ''): TestResponse
    {
        return $this->withToken(self::TOKEN)->getJson('/api/sync/editions'.($query === '' ? '' : "?{$query}"));
    }

    /** @return list<array{0: int, 1: int}> [category ID, is_primary] per shelf */
    private function shelvesOf(int $bookId): array
    {
        return DB::table('book_category')->where('book_id', $bookId)->orderBy('category_id')->get()
            ->map(fn ($row) => [(int) $row->category_id, (int) $row->is_primary])->all();
    }

    /** Straight to the column: Eloquent would stamp "now" over it. */
    private function setUpdatedAt(Edition $edition, string $when): void
    {
        DB::table('editions')->where('id', $edition->id)->update(['updated_at' => $when]);
    }
}
