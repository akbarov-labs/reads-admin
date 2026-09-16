<?php

namespace Tests\Feature\Api;

use App\Enums\BookRole;
use App\Enums\UserRole;
use App\Models\Author;
use App\Models\Book;
use App\Models\Publisher;
use App\Models\Translator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /api/books is the site-wide catalogue, as opposed to the per-profile book
 * list nested inside /api/translators/{slug}. It serialises through
 * CatalogBookResource, which adds the owning translator on top of the shared
 * BookResource fields.
 *
 * The interesting part is the author/publisher columns: every book still
 * carries the free-text `author` and `publisher` strings it was created with
 * before the catalogue tables existed, and the resource prefers the linked
 * record when there is one. Both halves of that are pinned here, because a
 * book that silently reverts to its legacy string is how a renamed author
 * comes back from the dead.
 */
class BookApiTest extends TestCase
{
    use RefreshDatabase;

    private function translator(string $slug = 'ilhom', string $name = 'Ilhom'): Translator
    {
        $user = User::create([
            'name' => $name,
            'email' => "{$slug}@example.test",
            'password' => 'password',
            'role' => UserRole::Translator,
        ]);

        return Translator::create([
            'user_id' => $user->id,
            'slug' => $slug,
            'name' => $name,
            'years_active' => 5,
        ]);
    }

    private function book(array $attributes = []): Book
    {
        return Book::create(array_merge([
            'translator_id' => $attributes['translator_id'] ?? $this->translator()->id,
            'uzbek_title' => 'Chol va dengiz',
            'original_title' => 'The Old Man and the Sea',
            'author' => 'Ernest Hemingway',
            'source_language' => 'en',
            'year' => 1952,
        ], $attributes));
    }

    public function test_the_collection_matches_the_shape_the_site_expects(): void
    {
        $book = $this->book([
            'publisher' => 'Yangi asr avlodi',
            'cover_path' => 'covers/chol.jpg',
            'role' => BookRole::Translator,
        ]);

        $this->getJson('/api/books')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $book->id)
            ->assertJsonPath('data.0.role', 'translator')
            ->assertJsonPath('data.0.uzbekTitle', 'Chol va dengiz')
            ->assertJsonPath('data.0.originalTitle', 'The Old Man and the Sea')
            ->assertJsonPath('data.0.author', 'Ernest Hemingway')
            ->assertJsonPath('data.0.sourceLanguage', 'en')
            ->assertJsonPath('data.0.publisher', 'Yangi asr avlodi')
            ->assertJsonPath('data.0.year', 1952)
            ->assertJsonPath('data.0.coverUrl', '/storage/covers/chol.jpg');
    }

    public function test_a_missing_cover_is_null_rather_than_an_empty_path(): void
    {
        $this->book();

        // BookCard falls back to a typographic placeholder on null; an
        // empty string would put next/image on "/storage/" instead.
        $this->getJson('/api/books')->assertJsonPath('data.0.coverUrl', null);
    }

    public function test_each_book_carries_the_translator_who_owns_it(): void
    {
        $this->book(['translator_id' => $this->translator('ilhom', 'Ilhom Salimov')->id]);

        // The site-wide catalogue is the one place a book appears outside
        // its own profile page, so the credit has to travel with it.
        $this->getJson('/api/books')
            ->assertJsonPath('data.0.translator.slug', 'ilhom')
            ->assertJsonPath('data.0.translator.name', 'Ilhom Salimov');
    }

    public function test_a_linked_author_wins_over_the_legacy_string(): void
    {
        $author = Author::create(['slug' => 'ernest-hemingway', 'name' => 'Ernest Hemingway']);

        $book = $this->book([
            'author_id' => $author->id,
            // Deliberately stale: this is the value the row was imported
            // with, before anyone tidied the spelling in the catalogue.
            'author' => 'E. Hemingway',
        ]);

        $this->getJson('/api/books')
            ->assertJsonPath('data.0.author', 'Ernest Hemingway')
            ->assertJsonPath('data.0.authorId', (string) $author->id);

        $this->assertSame('E. Hemingway', $book->fresh()->getRawOriginal('author'));
    }

    public function test_a_book_with_no_linked_author_still_shows_its_string(): void
    {
        $this->book(['author' => 'Ernest Hemingway']);

        $this->getJson('/api/books')
            ->assertJsonPath('data.0.author', 'Ernest Hemingway')
            ->assertJsonPath('data.0.authorId', null);
    }

    public function test_a_linked_publisher_is_exposed_alongside_the_legacy_string(): void
    {
        $publisher = Publisher::create(['slug' => 'yangi-asr-avlodi', 'name' => 'Yangi asr avlodi']);

        $this->book([
            'publisher_id' => $publisher->id,
            'publisher' => 'Yangi Asr',
        ]);

        $this->getJson('/api/books')
            ->assertJsonPath('data.0.publisherId', (string) $publisher->id)
            ->assertJsonPath('data.0.publisherName', 'Yangi asr avlodi')
            // `publisher` stays the raw column: BookCard reads it directly
            // and changing it here would be a silent contract change.
            ->assertJsonPath('data.0.publisher', 'Yangi Asr');
    }

    public function test_an_excerpt_is_only_present_when_both_halves_are_filled_in(): void
    {
        $this->book([
            'excerpt_source_language' => 'en',
            'excerpt_source_text' => 'He was an old man...',
            'excerpt_translated_text' => 'U chol edi...',
        ]);

        $this->getJson('/api/books')
            ->assertJsonPath('data.0.excerpt.sourceLanguage', 'en')
            ->assertJsonPath('data.0.excerpt.sourceText', 'He was an old man...')
            ->assertJsonPath('data.0.excerpt.translatedText', 'U chol edi...');
    }

    public function test_a_half_filled_excerpt_is_omitted(): void
    {
        // ExcerptReader renders a side-by-side pair; one side alone is not
        // an excerpt, it is a half-finished draft.
        $this->book(['excerpt_source_text' => 'He was an old man...']);

        $this->getJson('/api/books')->assertJsonPath('data.0.excerpt', null);
    }

    public function test_the_collection_is_ordered_by_sort_order_then_id(): void
    {
        $translator = $this->translator();

        $this->book(['translator_id' => $translator->id, 'uzbek_title' => 'Third', 'sort_order' => 2]);
        $this->book(['translator_id' => $translator->id, 'uzbek_title' => 'First', 'sort_order' => 0]);
        $this->book(['translator_id' => $translator->id, 'uzbek_title' => 'Second', 'sort_order' => 0]);

        // sort_order is what a translator drags in the panel; id breaks the
        // tie so the order is stable between requests rather than left to
        // whatever MySQL feels like returning.
        $this->getJson('/api/books')
            ->assertJsonPath('data.0.uzbekTitle', 'First')
            ->assertJsonPath('data.1.uzbekTitle', 'Second')
            ->assertJsonPath('data.2.uzbekTitle', 'Third');
    }

    public function test_the_catalogue_spans_every_translator(): void
    {
        $this->book(['translator_id' => $this->translator('ilhom', 'Ilhom')->id]);
        $this->book(['translator_id' => $this->translator('dilnoza', 'Dilnoza')->id, 'uzbek_title' => 'Qor']);

        $this->getJson('/api/books')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_single_book_can_be_fetched(): void
    {
        $book = $this->book();

        $this->getJson("/api/books/{$book->id}")
            ->assertOk()
            ->assertJsonPath('data.uzbekTitle', 'Chol va dengiz')
            ->assertJsonPath('data.translator.slug', 'ilhom');
    }

    public function test_an_unknown_book_is_a_404(): void
    {
        $this->getJson('/api/books/404404')->assertNotFound();
    }

    public function test_the_endpoints_need_no_authentication(): void
    {
        $this->book();

        $this->getJson('/api/books')->assertOk();
    }
}
