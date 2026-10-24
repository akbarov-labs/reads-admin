<?php

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\BookView;
use App\Models\SearchQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The site reports book pages opened (/api/views) and searches readers
 * settled on (/api/searches), next to its store clicks.
 */
class SiteStatsApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'site-shared-secret';

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        config(['seo.revalidate.secret' => self::SECRET]);
        $this->book = Book::create(['uzbek_title' => "O'tkan kunlar"]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function openBook(array $overrides = [], ?string $token = self::SECRET)
    {
        $request = $token === null ? $this : $this->withToken($token);

        return $request->postJson('/api/views', [
            'book_id' => $this->book->id,
            'locale' => 'uz',
            'ip' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0 (iPhone)',
            ...$overrides,
        ]);
    }

    private function search(array $overrides = [], ?string $token = self::SECRET)
    {
        $request = $token === null ? $this : $this->withToken($token);

        return $request->postJson('/api/searches', [
            'query' => '  Oʻtkan   kunlar ',
            'results' => 3,
            'source' => 'page',
            'locale' => 'uz',
            'ip' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0 (iPhone)',
            ...$overrides,
        ]);
    }

    public function test_a_view_adds_to_the_books_counter_for_the_day(): void
    {
        CarbonImmutable::setTestNow('2026-10-24 20:00:00'); // 01:00 on the 25th in Tashkent

        $this->openBook()->assertNoContent();
        $this->openBook(['ip' => '198.51.100.7'])->assertNoContent();
        $this->openBook(['ip' => '198.51.100.8', 'locale' => 'ru'])->assertNoContent();

        $uz = BookView::where('locale', 'uz')->sole();
        $this->assertSame('2026-10-25', $uz->day);
        $this->assertSame(2, $uz->views);
        $this->assertSame(1, BookView::where('locale', 'ru')->sole()->views);
    }

    public function test_a_reload_is_one_view_until_half_an_hour_has_passed(): void
    {
        CarbonImmutable::setTestNow('2026-10-24 06:00:00');

        $this->openBook();
        $this->openBook();
        $this->assertSame(1, BookView::sole()->views);

        $this->travel(31)->minutes();
        $this->openBook();
        $this->assertSame(2, BookView::sole()->views);
    }

    public function test_a_search_is_stored_tidied_with_a_key_that_ignores_spelling(): void
    {
        $this->search()->assertNoContent();
        $this->search(['query' => 'ЎТКАН КУНЛАР', 'ip' => '198.51.100.7'])->assertNoContent();

        $searches = SearchQuery::orderBy('id')->get();
        $this->assertSame("O'tkan kunlar", $searches[0]->query);
        $this->assertSame($searches[0]->query_key, $searches[1]->query_key);
        $this->assertSame(3, $searches[0]->results);
        $this->assertSame('page', $searches[0]->source);
        $this->assertNull($searches[0]->picked);
        $this->assertSame(64, strlen($searches[0]->visitor_hash));
    }

    public function test_a_pick_from_the_box_keeps_the_page_and_no_result_count(): void
    {
        $this->search(['source' => 'box', 'results' => 9, 'picked' => '/book/12'])->assertNoContent();

        $search = SearchQuery::sole();
        $this->assertSame('/book/12', $search->picked);
        $this->assertNull($search->results);
    }

    public function test_the_same_search_from_the_same_visitor_is_counted_once(): void
    {
        $this->search();
        $this->search(['query' => "o'tkan kunlar"]);
        $this->search(['source' => 'box', 'picked' => '/book/1']);

        // Page and box are told apart: one is a listing, the other a pick.
        $this->assertSame(2, SearchQuery::count());
    }

    public function test_long_queries_are_cut_and_empty_ones_dropped(): void
    {
        $this->search(['query' => str_repeat('kitob ', 80)])->assertNoContent();
        $this->search(['query' => '  ...  ', 'ip' => '198.51.100.7'])->assertNoContent();

        $this->assertSame(1, SearchQuery::count());
        $this->assertLessThanOrEqual(100, mb_strlen(SearchQuery::sole()->query));
    }

    public function test_only_the_site_may_write(): void
    {
        $this->openBook([], null)->assertUnauthorized();
        $this->search([], 'wrong')->assertUnauthorized();

        $this->assertSame(0, BookView::count());
        $this->assertSame(0, SearchQuery::count());
    }

    public function test_junk_is_rejected(): void
    {
        $this->openBook(['book_id' => 999999])->assertUnprocessable();
        $this->openBook(['locale' => null])->assertUnprocessable();
        $this->search(['source' => 'other'])->assertUnprocessable();
        $this->search(['source' => 'box', 'picked' => 'https://evil.example/x'])->assertUnprocessable();

        $this->assertSame(0, BookView::count());
        $this->assertSame(0, SearchQuery::count());
    }
}
