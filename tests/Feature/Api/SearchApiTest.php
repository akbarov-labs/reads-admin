<?php

namespace Tests\Feature\Api;

use App\Enums\ModerationStatus;
use App\Models\Author;
use App\Models\AuthorAlias;
use App\Models\Book;
use App\Models\Translator;
use App\Support\SearchKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * /api/search feeds the site's search-as-you-type box (Reads
 * app/api/search/route.ts, typed in lib/search.ts). The group and field
 * names are a contract the same way the list endpoints' are.
 */
class SearchApiTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    public function test_one_query_answers_for_all_four_kinds(): void
    {
        $translator = $this->translatorWithAccount('ibrohim-gafurov', "Ibrohim G'afurov");
        $book = $this->catalogueBook(
            ['uzbek_title' => "O'tkan kunlar", 'original_title' => null],
            ['year' => 2019, 'publisher_id' => $this->publisher("G'afur G'ulom")->id, 'cover_path' => 'covers/otkan.jpg'],
            authors: [$this->author('Abdulla Qodiriy')],
            credits: [$translator],
        );

        $this->getJson('/api/search?q=otkan')
            ->assertOk()
            ->assertJsonPath('books.total', 1)
            ->assertJsonPath('books.items.0.id', (string) $book->id)
            ->assertJsonPath('books.items.0.uzbekTitle', "O'tkan kunlar")
            ->assertJsonPath('books.items.0.author', 'Abdulla Qodiriy')
            ->assertJsonPath('books.items.0.year', 2019)
            ->assertJsonPath('books.items.0.coverUrl', '/storage/covers/otkan.jpg')
            ->assertJsonPath('authors.total', 0);

        $this->getJson('/api/search?q=qodiriy')
            ->assertJsonPath('authors.items.0.slug', 'abdulla-qodiriy')
            ->assertJsonPath('authors.items.0.name', 'Abdulla Qodiriy')
            ->assertJsonPath('authors.items.0.bookCount', 1);

        $this->getJson('/api/search?q=gafurov')
            ->assertJsonPath('translators.items.0.slug', 'ibrohim-gafurov')
            ->assertJsonPath('translators.items.0.bookCount', 1)
            ->assertJsonPath('publishers.total', 0);

        $this->getJson('/api/search?q=gafur gulom')
            ->assertJsonPath('publishers.items.0.slug', 'gafur-gulom')
            ->assertJsonPath('publishers.items.0.name', "G'afur G'ulom")
            ->assertJsonPath('publishers.items.0.bookCount', 1);
    }

    public function test_alphabet_case_and_apostrophes_do_not_matter(): void
    {
        $this->catalogueBook(['uzbek_title' => "O'tkan kunlar", 'original_title' => null]);

        foreach (['otkan', "O'TKAN", 'Oʻtkan', 'o‘tk', 'O`tkan kun', 'Ўткан', 'ўткан кунлар', 'kunlar otkan'] as $typed) {
            $this->getJson('/api/search?q='.urlencode($typed))->assertJsonPath('books.total', 1, $typed);
        }
    }

    public function test_a_term_must_start_a_word(): void
    {
        $this->catalogueBook(['uzbek_title' => "O'tkan kunlar", 'original_title' => null]);

        $this->getJson('/api/search?q=unlar')->assertJsonPath('books.total', 0);
        $this->getJson('/api/search?q=otkan zamon')->assertJsonPath('books.total', 0);
    }

    public function test_the_closest_match_comes_first_and_the_total_counts_the_rest(): void
    {
        $this->catalogueBook(['uzbek_title' => 'Dengiz hikoyalari', 'original_title' => null]);
        $this->catalogueBook(['uzbek_title' => 'Ochiq dengiz', 'original_title' => null]);
        $this->catalogueBook(['uzbek_title' => 'Dengiz', 'original_title' => null]);
        $this->catalogueBook(['uzbek_title' => 'Chol va dengiz', 'original_title' => null]);

        $response = $this->getJson('/api/search?q=dengiz&limit=2')->assertOk();

        $this->assertSame(['Dengiz', 'Dengiz hikoyalari'], array_column($response->json('books.items'), 'uzbekTitle'));
        $response->assertJsonPath('books.total', 4);
    }

    public function test_a_book_is_found_by_its_original_title(): void
    {
        $this->catalogueBook(['uzbek_title' => 'Chol va dengiz', 'original_title' => 'The Old Man and the Sea']);

        $this->getJson('/api/search?q=old man')->assertJsonPath('books.total', 1);
    }

    public function test_what_the_site_does_not_show_is_not_found(): void
    {
        $hidden = $this->author('Yashirin Muallif');
        $this->catalogueBook(['uzbek_title' => 'Yashirin kitob', 'status' => ModerationStatus::Pending->value], authors: [$hidden]);
        Translator::create(['slug' => 'yashirin-tarjimon', 'name' => 'Yashirin Tarjimon']);

        $this->getJson('/api/search?q=yashirin')
            ->assertJsonPath('books.total', 0)
            ->assertJsonPath('authors.total', 0)
            ->assertJsonPath('translators.total', 0);
    }

    public function test_a_translator_with_a_profile_is_found_before_their_first_book(): void
    {
        $this->translatorWithAccount('yangi-tarjimon', 'Yangi Tarjimon');

        $this->getJson('/api/search?q=yangi tarjimon')
            ->assertJsonPath('translators.items.0.slug', 'yangi-tarjimon')
            ->assertJsonPath('translators.items.0.bookCount', 0);
    }

    public function test_an_author_is_found_by_a_name_merged_into_them(): void
    {
        $author = $this->author('Alisher Navoiy');
        $this->catalogueBook(['uzbek_title' => 'Xamsa'], authors: [$author]);
        AuthorAlias::create(['author_id' => $author->id, 'name' => 'Alisher Navoi']);

        $this->getJson('/api/search?q=navoi')
            ->assertJsonPath('authors.total', 1)
            ->assertJsonPath('authors.items.0.slug', 'alisher-navoiy');
    }

    public function test_a_query_too_short_to_mean_anything_returns_empty_groups(): void
    {
        $this->catalogueBook(['uzbek_title' => 'A kitobi']);

        foreach (['', 'a', "'", ' - '] as $typed) {
            $this->getJson('/api/search?q='.urlencode($typed))
                ->assertOk()
                ->assertExactJson([
                    'books' => ['items' => [], 'total' => 0],
                    'authors' => ['items' => [], 'total' => 0],
                    'translators' => ['items' => [], 'total' => 0],
                    'publishers' => ['items' => [], 'total' => 0],
                ]);
        }
    }

    public function test_typed_punctuation_is_not_a_wildcard(): void
    {
        $this->catalogueBook(['uzbek_title' => 'Dengiz']);

        $this->getJson('/api/search?q='.urlencode('d%'))->assertJsonPath('books.total', 0);
        $this->getJson('/api/search?q='.urlencode('de_giz'))->assertJsonPath('books.total', 0);
    }

    public function test_the_key_follows_a_rename_even_with_events_off(): void
    {
        $book = $this->catalogueBook(['uzbek_title' => 'Eski nom']);

        // How the catalogue sync and the Asaxiy import write.
        Model::withoutEvents(fn () => $book->update(['uzbek_title' => 'Yangi nom']));

        $this->getJson('/api/search?q=yangi nom')->assertJsonPath('books.total', 1);
        $this->getJson('/api/search?q=eski')->assertJsonPath('books.total', 0);
    }

    public function test_rebuild_repairs_a_key_a_bulk_update_left_behind(): void
    {
        $author = $this->author('Lev Tolstoy');
        $this->catalogueBook(['uzbek_title' => 'Urush va tinchlik'], authors: [$author]);
        Author::whereKey($author->id)->update(['original_name' => 'Лев Николаевич Толстой']);

        $this->getJson('/api/search?q=nikolayevich')->assertJsonPath('authors.total', 0);

        $this->assertSame(1, SearchKeys::rebuild()['authors']);
        $this->getJson('/api/search?q=nikolayevich')->assertJsonPath('authors.total', 1);
        $this->assertSame(0, SearchKeys::rebuild()['authors']);
    }

    public function test_the_full_books_listing_finds_what_the_box_suggests(): void
    {
        $this->catalogueBook(['uzbek_title' => "O'tkan kunlar", 'original_title' => null]);

        $this->getJson('/api/books?q=otkan')->assertJsonCount(1, 'data');
        $this->getJson('/api/books?q='.urlencode('Ўткан'))->assertJsonCount(1, 'data');

        $this->catalogueBook(['uzbek_title' => 'Urush va tinchlik'], authors: [Author::create(['slug' => 'lev-tolstoy', 'name' => 'Lev Tolstoy', 'original_name' => 'Лев Толстой'])]);
        $this->getJson('/api/books?q='.urlencode('толстой'))->assertJsonCount(1, 'data');
    }

    public function test_each_visitor_the_site_forwards_has_a_limit_of_their_own(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/search?q=dengiz', ['X-Forwarded-For' => '203.0.113.9'])->assertOk();
        }

        $this->getJson('/api/search?q=dengiz', ['X-Forwarded-For' => '203.0.113.9'])->assertStatus(429);
        $this->getJson('/api/search?q=dengiz', ['X-Forwarded-For' => '198.51.100.7'])->assertOk();
    }

    public function test_the_migration_fills_in_keys_for_rows_already_there(): void
    {
        $this->catalogueBook(['uzbek_title' => "O'tkan kunlar"]);
        DB::table('books')->update(['search_key' => null]);

        $migration = require database_path('migrations/2026_10_24_000001_add_search_key_to_catalogue_tables.php');
        $migration->down();
        $migration->up();

        $this->assertSame('otkan kunlar the old man and the sea', Book::first()->search_key);
    }
}
