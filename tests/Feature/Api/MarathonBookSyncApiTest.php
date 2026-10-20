<?php

namespace Tests\Feature\Api;

use App\Enums\ModerationStatus;
use App\Models\Author;
use App\Models\Book;
use App\Models\Edition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * The Kitob Marafoni bot's way into the catalogue: its own token on
 * /api/sync/*, and POST /api/sync/books for a book with no ISBN, which
 * waits for an editor here. docs/DATA-MODEL.md, "Books without an ISBN".
 */
class MarathonBookSyncApiTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    private const KITOBLARIM_TOKEN = 'bot-sync-secret';

    private const MARATHON_TOKEN = 'marathon-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'catalogue.sync_token' => self::KITOBLARIM_TOKEN,
            'catalogue.marathon_token' => self::MARATHON_TOKEN,
        ]);
    }

    // ------------------------------------------------------------------
    // Tokens
    // ------------------------------------------------------------------

    public function test_with_neither_token_configured_sync_is_off(): void
    {
        config(['catalogue.sync_token' => null, 'catalogue.marathon_token' => '']);

        $this->withToken(self::MARATHON_TOKEN)->postJson('/api/sync/books', $this->body())
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Catalogue sync is not configured']);
        $this->postJson('/api/sync/books', $this->body())->assertStatus(503);

        $this->assertSame(0, Book::count());
    }

    public function test_either_bots_token_is_let_in_and_anything_else_refused(): void
    {
        $this->postJson('/api/sync/books', $this->body())->assertUnauthorized();
        $this->withToken('wrong')->postJson('/api/sync/books', $this->body())->assertUnauthorized();
        $this->withToken(self::MARATHON_TOKEN.'x')->postJson('/api/sync/books', $this->body())->assertUnauthorized();
        $this->assertSame(0, Book::count());

        $this->withToken(self::MARATHON_TOKEN)->getJson('/api/sync/editions')->assertOk();
        $this->withToken(self::KITOBLARIM_TOKEN)->getJson('/api/sync/editions')->assertOk();
        $this->withToken(self::MARATHON_TOKEN)->postJson('/api/sync/books', $this->body())->assertOk();
    }

    public function test_one_bot_works_while_the_others_token_is_unset(): void
    {
        config(['catalogue.sync_token' => null]);

        // An unset token matches nothing, not even an empty-looking header.
        $this->withToken(self::KITOBLARIM_TOKEN)->getJson('/api/sync/editions')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer ')->getJson('/api/sync/editions')->assertUnauthorized();
        $this->withToken(self::MARATHON_TOKEN)->getJson('/api/sync/editions')->assertOk();

        config(['catalogue.sync_token' => self::KITOBLARIM_TOKEN, 'catalogue.marathon_token' => null]);

        $this->withToken(self::MARATHON_TOKEN)->getJson('/api/sync/editions')->assertUnauthorized();
        $this->withToken(self::KITOBLARIM_TOKEN)->getJson('/api/sync/editions')->assertOk();
    }

    public function test_the_marathon_bot_can_push_an_isbn_edition_too(): void
    {
        $this->withToken(self::MARATHON_TOKEN)->putJson('/api/sync/editions/9789943231986', [
            'title' => 'Mehrobdan chayon',
            'authors' => ['Abdulla Qodiriy'],
            'updatedAt' => '2026-10-01T10:00:00Z',
        ])->assertOk()->assertJsonPath('outcome', 'created');
    }

    public function test_each_bot_has_its_own_rate_limit_bucket_apart_from_strangers(): void
    {
        for ($i = 0; $i < 600; $i++) {
            $status = $this->withToken('wrong')->getJson('/api/sync/editions')->status();
            $this->assertContains($status, [401, 429]);
        }

        $this->withToken(self::MARATHON_TOKEN)->getJson('/api/sync/editions')->assertOk();
        $this->withToken(self::KITOBLARIM_TOKEN)->getJson('/api/sync/editions')->assertOk();
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_a_new_book_is_created_pending_with_its_authors_and_an_isbnless_edition(): void
    {
        $this->pushBook([
            'title' => 'Sariq devni minib',
            'authors' => ["Xudoyberdi To'xtaboyev", 'Ikkinchi Muallif'],
            'language' => 'uz',
            'description' => 'Hashamjon haqida qiziqarli sarguzasht qissa.',
        ])
            ->assertOk()
            ->assertJsonPath('outcome', 'created')
            ->assertJsonPath('status', 'pending')
            ->assertJsonStructure(['outcome', 'bookId', 'status']);

        $book = Book::with(['authors', 'editions'])->sole();

        $this->assertSame(ModerationStatus::Pending, $book->status);
        $this->assertSame('Sariq devni minib', $book->uzbek_title);
        $this->assertSame(["Xudoyberdi To'xtaboyev", 'Ikkinchi Muallif'], $book->authors->pluck('name')->all());
        $this->assertSame([0, 1], $book->authors->map(fn ($author) => $author->pivot->position)->all());

        $edition = $book->editions->sole();
        $this->assertNull($edition->isbn);
        $this->assertNull($edition->title, 'the edition is titled like its book');
        $this->assertSame("O'zbek tili", $edition->language);
        $this->assertSame('Hashamjon haqida qiziqarli sarguzasht qissa.', $edition->description);
        $this->assertSame(ModerationStatus::Approved, $edition->status);
    }

    public function test_a_pending_book_is_not_on_the_site_until_an_editor_approves_it(): void
    {
        $bookId = $this->pushBook()->json('bookId');

        $this->getJson('/api/books')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/books/{$bookId}")->assertNotFound();

        // Approving the book is the whole review: it already has an edition.
        Book::findOrFail($bookId)->update(['status' => ModerationStatus::Approved]);

        $this->getJson('/api/books')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/books/{$bookId}")->assertOk();
    }

    public function test_an_isbnless_book_never_reaches_the_kitoblarim_feed(): void
    {
        $bookId = $this->pushBook()->json('bookId');
        Book::findOrFail($bookId)->update(['status' => ModerationStatus::Approved]);

        $this->withToken(self::KITOBLARIM_TOKEN)->getJson('/api/sync/editions')
            ->assertOk()
            ->assertExactJson(['items' => [], 'next' => null]);
    }

    public function test_text_is_cleaned_and_cyrillic_made_latin_for_uzbek(): void
    {
        $this->pushBook([
            'title' => '  Меҳробдан   чаён ',
            'authors' => ['Абдулла Қодирий'],
            'language' => 'uz',
        ])->assertOk();

        $book = Book::with('authors')->sole();
        $this->assertSame('Mehrobdan chayon', $book->uzbek_title);
        $this->assertSame('Abdulla Qodiriy', $book->authors->sole()->name);
    }

    public function test_a_book_without_authors_is_created_and_found_again(): void
    {
        $first = $this->pushBook(['authors' => []])->assertJsonPath('outcome', 'created')->json('bookId');
        $this->pushBook(['authors' => null])->assertJsonPath('outcome', 'found')->assertJsonPath('bookId', $first);

        $this->assertSame(1, Book::count());
        $this->assertSame(0, Author::count());
    }

    public function test_the_body_is_validated(): void
    {
        $this->pushBook(['title' => null])->assertStatus(422)->assertJsonValidationErrors(['title']);
        $this->pushBook(['title' => " \u{200B} "])->assertStatus(422)->assertJsonValidationErrors(['title']);
        $this->pushBook(['authors' => 'Oybek'])->assertStatus(422)->assertJsonValidationErrors(['authors']);
        $this->pushBook(['description' => ['not text']])->assertStatus(422)->assertJsonValidationErrors(['description']);

        $this->assertSame(0, Book::count());
    }

    public function test_creating_a_pending_book_does_not_ping_the_public_site(): void
    {
        config(['seo.revalidate.url' => 'http://site.test/api/revalidate', 'seo.revalidate.secret' => 'secret']);
        Http::fake();

        $this->pushBook()->assertJsonPath('outcome', 'created');

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Find
    // ------------------------------------------------------------------

    public function test_an_existing_book_is_found_by_author_and_title_in_any_alphabet_and_left_alone(): void
    {
        $book = $this->catalogueBook(
            ['uzbek_title' => 'Mehrobdan chayon', 'description' => null],
            ['isbn' => '9789943231986', 'status' => ModerationStatus::Approved],
            authors: [$this->author('Абдулла Қодирий')],
        );
        $updatedAt = $book->fresh()->updated_at;

        $this->pushBook([
            'title' => 'Mehrobdan chayon',
            'authors' => ['Abdulla Qodiriy'],
            'description' => 'Bunday matn kitobga yozilmaydi.',
        ])
            ->assertOk()
            ->assertExactJson(['outcome' => 'found', 'bookId' => $book->id, 'status' => 'approved']);

        $this->assertSame(1, Book::count());
        $this->assertSame(1, Author::count());
        $this->assertSame(1, Edition::count(), 'a found book gets no new edition');
        $this->assertNull($book->fresh()->description);
        $this->assertEquals($updatedAt, $book->fresh()->updated_at);
    }

    public function test_a_different_title_by_the_same_author_is_a_new_book(): void
    {
        $this->catalogueBook(['uzbek_title' => "O'tkan kunlar"], authors: [$this->author('Abdulla Qodiriy')]);

        $this->pushBook(['title' => 'Mehrobdan chayon', 'authors' => ['Abdulla Qodiriy']])
            ->assertJsonPath('outcome', 'created');

        $this->assertSame(2, Book::count());
        $this->assertSame(1, Author::count());
    }

    public function test_a_book_an_editor_rejected_is_found_not_made_again(): void
    {
        $book = $this->catalogueBook(['uzbek_title' => 'Sariq devni minib', 'status' => ModerationStatus::Rejected], authors: [$this->author("Xudoyberdi To'xtaboyev")]);

        $this->pushBook()->assertExactJson(['outcome' => 'found', 'bookId' => $book->id, 'status' => 'rejected']);

        $this->assertSame(1, Book::count());
    }

    // ------------------------------------------------------------------
    // Idempotency
    // ------------------------------------------------------------------

    public function test_the_same_book_sent_twice_is_one_book(): void
    {
        $first = $this->pushBook()->assertJsonPath('outcome', 'created')->json('bookId');

        $this->pushBook()
            ->assertOk()
            ->assertExactJson(['outcome' => 'found', 'bookId' => $first, 'status' => 'pending']);

        // Another spelling of the same title and author is the same book too.
        $this->pushBook(['title' => 'Сариқ девни миниб', 'authors' => ['Худойберди Тўхтабоев'], 'language' => 'uz'])
            ->assertJsonPath('outcome', 'found')
            ->assertJsonPath('bookId', $first);

        $this->assertSame(1, Book::count());
        $this->assertSame(1, Edition::count());
        $this->assertSame(1, Author::count());
    }

    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function body(): array
    {
        return [
            'title' => 'Sariq devni minib',
            'authors' => ["Xudoyberdi To'xtaboyev"],
            'language' => 'uz',
            'description' => null,
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    private function pushBook(array $overrides = []): TestResponse
    {
        return $this->withToken(self::MARATHON_TOKEN)->postJson('/api/sync/books', [...$this->body(), ...$overrides]);
    }
}
