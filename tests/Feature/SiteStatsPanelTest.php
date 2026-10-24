<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Stats\ClicksPerDayChart;
use App\Filament\Stats\NoResultSearchesTable;
use App\Filament\Stats\SiteStatsOverview;
use App\Filament\Stats\TopBooksTable;
use App\Filament\Stats\TopSearchesTable;
use App\Filament\Stats\TopStoresTable;
use App\Filament\Stats\ViewsAndSearchesChart;
use App\Models\Book;
use App\Models\BookView;
use App\Models\OutboundClick;
use App\Models\Publisher;
use App\Models\SearchQuery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The Site stats page: book views, store clicks per book per store, and searches. */
class SiteStatsPanelTest extends TestCase
{
    use RefreshDatabase;

    private Book $tazarru;

    private Book $kunlar;

    private Book $unclicked;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-24 12:00:00');

        $this->tazarru = Book::create(['uzbek_title' => 'Tazarru']);
        $this->kunlar = Book::create(['uzbek_title' => "O'tkan kunlar"]);
        $this->unclicked = Book::create(['uzbek_title' => 'Kecha va kunduz']);

        $click = fn (Book $book, string $target, string $at, string $visitor) => OutboundClick::create([
            'kind' => 'store', 'book_id' => $book->id, 'target' => $target,
            'locale' => 'uz', 'visitor_hash' => $visitor, 'created_at' => $at,
        ]);

        $click($this->tazarru, 'asaxiy', '2026-10-24 08:00:00', 'a');
        $click($this->tazarru, 'asaxiy', '2026-10-24 09:00:00', 'a');
        $click($this->tazarru, 'book.uz', '2026-10-23 09:00:00', 'b');
        $click($this->kunlar, 'qamar', '2026-10-22 09:00:00', 'c');
        // Outside the default 30 days.
        $click($this->kunlar, 'qamar', '2026-08-01 09:00:00', 'd');

        $view = fn (Book $book, string $day, int $views, string $locale = 'uz') => BookView::create([
            'book_id' => $book->id, 'day' => $day, 'locale' => $locale, 'views' => $views,
        ]);

        $view($this->tazarru, '2026-10-24', 20);
        $view($this->tazarru, '2026-10-23', 10, 'ru');
        $view($this->kunlar, '2026-10-22', 5);
        $view($this->unclicked, '2026-10-24', 40);
        $view($this->unclicked, '2026-08-01', 999);

        $search = fn (string $query, string $key, ?int $results, string $source, string $at, string $visitor) => SearchQuery::create([
            'query' => $query, 'query_key' => $key, 'results' => $results, 'source' => $source,
            'locale' => 'uz', 'visitor_hash' => $visitor, 'created_at' => $at,
        ]);

        $search("O'tkan kunlar", 'otkan kunlar', 3, 'page', '2026-10-24 07:00:00', 'a');
        $search('otkan kunlar', 'otkan kunlar', null, 'box', '2026-10-24 08:00:00', 'b');
        $search('ЎТКАН КУНЛАР', 'otkan kunlar', 3, 'page', '2026-10-23 08:00:00', 'c');
        $search('Garri Potter', 'garri potter', 0, 'page', '2026-10-24 10:00:00', 'a');
        $search('garri poter', 'garri poter', 0, 'page', '2026-10-24 10:01:00', 'a');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'a@example.test',
            'password' => 'password', 'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin);

        return $admin;
    }

    public function test_an_admin_opens_the_page(): void
    {
        $this->admin();

        $this->get('/admin/stats')->assertOk()->assertSee('Site stats');
    }

    public function test_publishers_cannot_see_it(): void
    {
        $user = User::create([
            'name' => 'Yangi asr', 'email' => 'p@example.test',
            'password' => 'password', 'role' => UserRole::Publisher,
        ]);
        Publisher::create(['user_id' => $user->id, 'slug' => 'yangi-asr', 'name' => 'Yangi asr']);

        $this->actingAs($user)->get('/admin/stats')->assertForbidden();
    }

    public function test_the_main_dashboard_does_not_carry_its_widgets(): void
    {
        $this->admin();

        $this->get('/admin')->assertOk()->assertDontSee('Clicks per day')->assertDontSee('Top searches');
    }

    public function test_books_are_ranked_by_views_with_their_clicks_and_a_column_per_store(): void
    {
        $this->admin();

        Livewire::test(TopBooksTable::class, ['filters' => []])
            // A book read about but never clicked through is listed too.
            ->assertCanSeeTableRecords([$this->unclicked, $this->tazarru, $this->kunlar], inOrder: true)
            ->assertTableColumnStateSet('views', 40, $this->unclicked->getKey())
            ->assertTableColumnStateSet('clicks', 0, $this->unclicked->getKey())
            ->assertTableColumnStateSet('views', 30, $this->tazarru->getKey())
            ->assertTableColumnStateSet('clicks', 3, $this->tazarru->getKey())
            ->assertTableColumnStateSet('click_through', '10.0%', $this->tazarru->getKey())
            ->assertTableColumnStateSet('visitors', 2, $this->tazarru->getKey())
            // asaxiy is the busiest store, so it is the first store column.
            ->assertTableColumnStateSet('store_0', 2, $this->tazarru->getKey())
            ->assertTableColumnStateSet('clicks', 1, $this->kunlar->getKey());
    }

    public function test_the_filters_narrow_every_count(): void
    {
        $this->admin();

        Livewire::test(TopBooksTable::class, ['filters' => ['from' => '2026-10-24', 'until' => '2026-10-24']])
            ->assertCanSeeTableRecords([$this->tazarru, $this->unclicked])
            ->assertCanNotSeeTableRecords([$this->kunlar])
            ->assertTableColumnStateSet('views', 20, $this->tazarru->getKey())
            ->assertTableColumnStateSet('clicks', 2, $this->tazarru->getKey());

        Livewire::test(TopBooksTable::class, ['filters' => ['locale' => 'ru']])
            ->assertCanSeeTableRecords([$this->tazarru])
            ->assertCanNotSeeTableRecords([$this->kunlar, $this->unclicked])
            ->assertTableColumnStateSet('views', 10, $this->tazarru->getKey())
            ->assertTableColumnStateSet('clicks', 0, $this->tazarru->getKey());

        // The store narrows clicks, not views.
        Livewire::test(TopBooksTable::class, ['filters' => ['target' => 'qamar']])
            ->assertTableColumnStateSet('clicks', 0, $this->tazarru->getKey())
            ->assertTableColumnStateSet('views', 30, $this->tazarru->getKey())
            ->assertTableColumnStateSet('clicks', 1, $this->kunlar->getKey());
    }

    public function test_stores_and_totals(): void
    {
        $this->admin();

        Livewire::test(TopStoresTable::class, ['filters' => []])
            ->assertSee(['asaxiy', 'book.uz', 'qamar']);

        Livewire::test(SiteStatsOverview::class, ['filters' => []])
            ->assertSeeInOrder([
                'Book views', '75',
                'Store clicks', '4', 'From 3 visitors',
                'Click-through', '5.3%',
                'Searches', '5', '1 picked from suggestions',
                'Searches with no results', '2', '50.0% of listing searches',
                'Busiest store', 'asaxiy',
            ]);
    }

    public function test_searches_are_grouped_across_spellings(): void
    {
        $this->admin();

        $component = Livewire::test(TopSearchesTable::class, ['filters' => []]);
        $records = $component->instance()->getTableRecords();

        $first = $records->first();
        $this->assertSame('otkan kunlar', $first->query_key);
        $this->assertSame(3, (int) $first->searches);
        $this->assertSame(3, (int) $first->visitors);
        $this->assertSame(1, (int) $first->picks);
        $this->assertSame(3, (int) $first->found);
        $this->assertCount(3, $records);
    }

    public function test_searches_that_found_nothing_are_listed_on_their_own(): void
    {
        $this->admin();

        $records = Livewire::test(NoResultSearchesTable::class, ['filters' => []])->instance()->getTableRecords();

        $this->assertEqualsCanonicalizing(['garri potter', 'garri poter'], $records->pluck('query_key')->all());
    }

    public function test_the_click_chart_has_a_line_per_store_and_a_point_per_day(): void
    {
        $this->admin();

        $chart = Livewire::test(ClicksPerDayChart::class, ['filters' => ['from' => '2026-10-22', 'until' => '2026-10-24']])->instance();
        $data = (fn () => $this->getData())->call($chart);

        $this->assertSame(['2026-10-22', '2026-10-23', '2026-10-24'], $data['labels']);
        $this->assertSame(['asaxiy', 'book.uz', 'qamar'], array_column($data['datasets'], 'label'));
        $this->assertSame([0, 0, 2], $data['datasets'][0]['data']);
        $this->assertSame([1, 0, 0], $data['datasets'][2]['data']);
    }

    public function test_the_views_chart_counts_views_searches_and_empty_searches_per_day(): void
    {
        $this->admin();

        $chart = Livewire::test(ViewsAndSearchesChart::class, ['filters' => ['from' => '2026-10-22 11:56:21', 'until' => '2026-10-24 09:00:00']])->instance();
        $data = (fn () => $this->getData())->call($chart);

        $this->assertSame(['2026-10-22', '2026-10-23', '2026-10-24'], $data['labels']);
        $this->assertSame([5, 10, 60], $data['datasets'][0]['data']);
        $this->assertSame([0, 1, 4], $data['datasets'][1]['data']);
        $this->assertSame([0, 0, 2], $data['datasets'][2]['data']);
    }
}
