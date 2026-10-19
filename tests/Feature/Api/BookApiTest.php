<?php

namespace Tests\Feature\Api;

use App\Enums\BookRole;
use App\Enums\ModerationStatus;
use App\Models\Category;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * /api/books is the site-wide catalogue: works, a page at a time, each shown
 * through its primary edition. /api/books/{id} is the book's own page, with
 * every edition.
 *
 * The flat card fields (publisher, year, coverUrl, translator …) predate
 * editions and are what the site's BookCard reads, so they are pinned here
 * alongside the new ones.
 */
class BookApiTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    public function test_the_collection_matches_the_shape_the_site_expects(): void
    {
        $translator = $this->translatorWithAccount('ilhom', 'Ilhom Salimov');
        $book = $this->catalogueBook(
            edition: ['publisher_id' => $this->publisher()->id, 'cover_path' => 'covers/chol.jpg'],
            credits: [$translator],
        );

        $this->getJson('/api/books')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $book->id)
            ->assertJsonPath('data.0.role', 'translator')
            ->assertJsonPath('data.0.uzbekTitle', 'Chol va dengiz')
            ->assertJsonPath('data.0.originalTitle', 'The Old Man and the Sea')
            ->assertJsonPath('data.0.author', 'Ernest Hemingway')
            ->assertJsonPath('data.0.authors.0.slug', 'ernest-hemingway')
            ->assertJsonPath('data.0.sourceLanguage', 'en')
            ->assertJsonPath('data.0.publisher', 'Yangi asr avlodi')
            ->assertJsonPath('data.0.publisherSlug', 'yangi-asr-avlodi')
            ->assertJsonPath('data.0.year', 1952)
            ->assertJsonPath('data.0.coverUrl', '/storage/covers/chol.jpg')
            ->assertJsonPath('data.0.translator.slug', 'ilhom')
            ->assertJsonPath('data.0.translators.0.role', 'translator')
            ->assertJsonPath('data.0.editionCount', 1)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_a_missing_cover_is_null_rather_than_an_empty_path(): void
    {
        $this->catalogueBook();

        // BookCard falls back to a typographic placeholder on null; an
        // empty string would put next/image on "/storage/" instead.
        $this->getJson('/api/books')->assertJsonPath('data.0.coverUrl', null);
    }

    public function test_several_authors_are_listed_in_credit_order(): void
    {
        $this->catalogueBook(authors: [$this->author('Ellen Feyn'), $this->author('Sherri Shnayder')]);

        $this->getJson('/api/books')
            ->assertJsonPath('data.0.author', 'Ellen Feyn, Sherri Shnayder')
            ->assertJsonPath('data.0.authors.1.name', 'Sherri Shnayder');
    }

    public function test_the_card_shows_the_edition_with_a_cover_then_the_newest(): void
    {
        $book = $this->catalogueBook(edition: ['year' => 2024]);
        $this->edition($book, ['year' => 2020, 'cover_path' => 'covers/old.jpg', 'publisher_id' => $this->publisher('Asaxiy Books')->id]);

        $this->getJson('/api/books')
            ->assertJsonPath('data.0.coverUrl', '/storage/covers/old.jpg')
            ->assertJsonPath('data.0.publisher', 'Asaxiy Books')
            ->assertJsonPath('data.0.editionCount', 2);
    }

    public function test_an_excerpt_is_only_present_when_both_halves_are_filled_in(): void
    {
        $this->catalogueBook(edition: [
            'excerpt_source_language' => 'en',
            'excerpt_source_text' => 'He was an old man...',
            'excerpt_translated_text' => 'U chol edi...',
        ]);

        $this->getJson('/api/books')
            ->assertJsonPath('data.0.excerpt.sourceLanguage', 'en')
            ->assertJsonPath('data.0.excerpt.translatedText', 'U chol edi...');
    }

    public function test_a_half_filled_excerpt_is_omitted(): void
    {
        $this->catalogueBook(edition: ['excerpt_source_text' => 'He was an old man...']);

        $this->getJson('/api/books')->assertJsonPath('data.0.excerpt', null);
    }

    public function test_the_collection_is_newest_first_and_stable(): void
    {
        $this->catalogueBook(['uzbek_title' => 'Old'], ['year' => 1990]);
        $this->catalogueBook(['uzbek_title' => 'New'], ['year' => 2024]);
        $this->catalogueBook(['uzbek_title' => 'Undated'], ['year' => null]);

        $this->getJson('/api/books')
            ->assertJsonPath('data.0.uzbekTitle', 'New')
            ->assertJsonPath('data.1.uzbekTitle', 'Old')
            ->assertJsonPath('data.2.uzbekTitle', 'Undated');

        $this->getJson('/api/books?sort=title')->assertJsonPath('data.0.uzbekTitle', 'New');
    }

    public function test_it_is_paginated(): void
    {
        foreach (range(1, 3) as $i) {
            $this->catalogueBook(['uzbek_title' => "Kitob {$i}"], ['year' => 2000 + $i]);
        }

        $this->getJson('/api/books?per_page=2')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/books?per_page=2&page=2')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uzbekTitle', 'Kitob 1');

        // A client cannot ask for the whole table in one go.
        $this->getJson('/api/books?per_page=100000')->assertJsonPath('meta.per_page', 100);
    }

    public function test_it_filters_by_author_publisher_translator_category_and_series(): void
    {
        $translator = $this->translatorWithAccount('dilnoza', 'Dilnoza');
        $series = Series::create(['slug' => 'garri-potter', 'name' => 'Garri Potter']);
        $parent = Category::create(['slug' => 'badiiy', 'name' => 'Badiiy adabiyot']);
        $genre = Category::create(['slug' => 'jahon', 'name' => 'Jahon adabiyoti', 'parent_id' => $parent->id]);

        $match = $this->catalogueBook(
            ['uzbek_title' => 'Match', 'series_id' => $series->id],
            ['publisher_id' => $this->publisher('Asaxiy Books')->id],
            [$this->author('Joan Rouling')],
            [$translator],
        );
        $match->categories()->attach($genre->id, ['is_primary' => true]);
        $this->catalogueBook(['uzbek_title' => 'Other']);

        foreach ([
            'author=joan-rouling',
            'publisher=asaxiy-books',
            'translator=dilnoza',
            'category=jahon',
            'category=badiiy', // a parent includes its children
            'series=garri-potter',
            'q=Rouling',
            'q=match',
        ] as $filter) {
            $this->getJson("/api/books?{$filter}")
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.uzbekTitle', 'Match');
        }
    }

    public function test_a_search_puts_the_title_typed_first_however_new_the_others_are(): void
    {
        $this->catalogueBook(['uzbek_title' => 'Taym mashin 1984'], ['isbn' => '9789943000001', 'year' => 2026]);
        $this->catalogueBook(['uzbek_title' => '1984 (Grafik roman)'], ['isbn' => '9789943000002', 'year' => 2025]);
        $this->catalogueBook(['uzbek_title' => '1984'], ['isbn' => '9789943000003', 'year' => 2020]);

        $this->getJson('/api/books?q=1984')
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.uzbekTitle', '1984')
            ->assertJsonPath('data.1.uzbekTitle', '1984 (Grafik roman)')
            ->assertJsonPath('data.2.uzbekTitle', 'Taym mashin 1984');

        // Whatever the case it was typed in.
        $this->getJson('/api/books?q=1984 (grafik ROMAN)')->assertJsonPath('data.0.uzbekTitle', '1984 (Grafik roman)');

        // An order the caller asked for is kept.
        $this->getJson('/api/books?q=1984&sort=year')->assertJsonPath('data.0.uzbekTitle', 'Taym mashin 1984');
    }

    public function test_an_isbn_search_finds_the_edition_however_it_is_typed(): void
    {
        $this->catalogueBook(edition: ['isbn' => '9789943231986']);

        $this->getJson('/api/books?q=978-9943-23-198-6')->assertJsonCount(1, 'data');
    }

    public function test_a_search_finds_o_apostrophe_however_the_apostrophe_is_typed(): void
    {
        $this->catalogueBook(['uzbek_title' => "O'tkan kunlar"]);

        foreach (["O'tkan kunlar", 'O‘tkan kunlar', 'Oʻtkan kunlar', 'O`tkan', 'O’tkan'] as $typed) {
            $this->getJson('/api/books?q='.urlencode($typed))
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.uzbekTitle', "O'tkan kunlar");
        }
    }

    public function test_pending_books_and_editions_stay_off_the_site(): void
    {
        $this->catalogueBook(['status' => ModerationStatus::Pending]);
        $this->catalogueBook(edition: ['status' => ModerationStatus::Pending]);

        $this->getJson('/api/books')->assertJsonCount(0, 'data');
    }

    public function test_a_book_without_its_own_description_shows_the_fullest_from_an_edition(): void
    {
        $about = "\"1984\" Jorj Oruell qalamiga mansub siyosiy antiutopik roman bo'lib, totalitar tuzumda yashayotgan Uinston Smitning taqdiri haqida hikoya qiladi.";
        $book = $this->catalogueBook(edition: ['isbn' => '9789910679629', 'description' => $about]);
        $this->edition($book, ['isbn' => '9789943231467', 'description' => "Yangi nashr ingliz tilidan qayta tahrir qilingan va imlo xatolari to'g'rilangan."]);
        $this->edition($book, ['isbn' => '9789943231962', 'description' => str_repeat('1984: (Jorj Oruell) - Qamar kitoblar do‘konida. Buyurtma bering, yetkazib beramiz. ', 3)]);
        $this->edition($book, ['isbn' => '9789943231970', 'description' => str_repeat('Rad etilgan nashr matni. ', 10), 'status' => ModerationStatus::Rejected]);

        $this->getJson("/api/books/{$book->id}")->assertJsonPath('data.description', $about);

        // Its own description, when it has one, wins.
        $book->update(['description' => 'Kitobning oʻz tavsifi.']);
        $this->getJson("/api/books/{$book->id}")->assertJsonPath('data.description', 'Kitobning oʻz tavsifi.');
    }

    public function test_a_book_whose_editions_only_carry_shop_text_or_notes_has_no_description(): void
    {
        $book = $this->catalogueBook(edition: ['isbn' => '9789943231962', 'description' => str_repeat('Kitob do‘konimizda. Buyurtma bering! ', 5)]);
        $this->edition($book, ['isbn' => '9789943231467', 'description' => 'Yangi nashr, tahrir qilingan.']);

        $this->getJson("/api/books/{$book->id}")->assertJsonPath('data.description', null);
    }

    public function test_a_single_book_lists_every_approved_edition_with_its_credits(): void
    {
        $first = $this->translatorWithAccount('ilhom', 'Ilhom');
        $second = $this->translatorWithAccount('dilnoza', 'Dilnoza');
        $book = $this->catalogueBook(edition: ['isbn' => '9789943231986', 'publisher_id' => $this->publisher()->id], credits: [$first]);
        $this->edition($book, ['isbn' => '9789943231672', 'pages' => 320], [[$second, BookRole::Translator], [$first, BookRole::Editor]]);
        $this->edition($book, ['status' => ModerationStatus::Pending]);

        $this->getJson("/api/books/{$book->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.editions')
            ->assertJsonPath('data.editions.0.isbn', '9789943231986')
            ->assertJsonPath('data.editions.0.publisher.name', 'Yangi asr avlodi')
            ->assertJsonPath('data.editions.1.pages', 320)
            ->assertJsonPath('data.editions.1.translators.0.slug', 'dilnoza')
            ->assertJsonPath('data.editions.1.translators.1.role', 'editor');
    }

    public function test_a_book_suggests_related_books_strongest_signal_first(): void
    {
        $author = $this->author('Tohir Malik');
        $book = $this->catalogueBook(['uzbek_title' => 'Shaytanat'], authors: [$author]);
        $sameAuthor = $this->catalogueBook(['uzbek_title' => 'Alvido bolalik'], authors: [$author]);
        $recommended = $this->catalogueBook(['uzbek_title' => 'Recommended'], authors: [$this->author('Boshqa')]);
        $this->catalogueBook(['uzbek_title' => 'Unrelated'], authors: [$this->author('Yana boshqa')]);

        $book->relatedBooks()->attach($recommended->id, ['source' => 'asaxiy', 'position' => 0]);

        $this->getJson("/api/books/{$book->id}")
            ->assertJsonCount(2, 'data.relatedBooks')
            ->assertJsonPath('data.relatedBooks.0.id', (string) $recommended->id)
            ->assertJsonPath('data.relatedBooks.1.id', (string) $sameAuthor->id);
    }

    public function test_an_unknown_or_pending_book_is_a_404(): void
    {
        $this->getJson('/api/books/404404')->assertNotFound();

        $pending = $this->catalogueBook(['status' => ModerationStatus::Pending]);
        $this->getJson("/api/books/{$pending->id}")->assertNotFound();
    }

    public function test_categories_are_served_as_a_tree_with_counts(): void
    {
        $parent = Category::create(['slug' => 'badiiy', 'name' => 'Badiiy adabiyot']);
        $child = Category::create(['slug' => 'jahon', 'name' => 'Jahon adabiyoti', 'parent_id' => $parent->id]);
        Category::create(['slug' => 'empty', 'name' => 'Nothing here']);
        $this->catalogueBook()->categories()->attach($child->id);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'badiiy')
            ->assertJsonPath('data.0.children.0.slug', 'jahon')
            ->assertJsonPath('data.0.children.0.bookCount', 1);
    }
}
