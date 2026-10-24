<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Clicks\ClicksPerDayChart;
use App\Filament\Clicks\ClickStatsOverview;
use App\Filament\Clicks\TopBooksTable;
use App\Filament\Clicks\TopStoresTable;
use App\Models\Book;
use App\Models\OutboundClick;
use App\Models\Publisher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The Store clicks page: clicks per book per store, per day. */
class ClickStatsPanelTest extends TestCase
{
    use RefreshDatabase;

    private Book $tazarru;

    private Book $kunlar;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-24 12:00:00');

        $this->tazarru = Book::create(['uzbek_title' => 'Tazarru']);
        $this->kunlar = Book::create(['uzbek_title' => "O'tkan kunlar"]);

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
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'a@example.test',
            'password' => 'password', 'role' => UserRole::Admin,
        ]);
    }

    public function test_an_admin_opens_the_page(): void
    {
        $this->actingAs($this->admin())->get('/admin/clicks')->assertOk()->assertSee('Store clicks');
    }

    public function test_publishers_cannot_see_it(): void
    {
        $user = User::create([
            'name' => 'Yangi asr', 'email' => 'p@example.test',
            'password' => 'password', 'role' => UserRole::Publisher,
        ]);
        Publisher::create(['user_id' => $user->id, 'slug' => 'yangi-asr', 'name' => 'Yangi asr']);

        $this->actingAs($user)->get('/admin/clicks')->assertForbidden();
    }

    public function test_the_main_dashboard_does_not_carry_its_widgets(): void
    {
        $this->actingAs($this->admin())->get('/admin')->assertOk()->assertDontSee('Clicks per day');
    }

    public function test_books_are_ranked_by_clicks_with_a_column_per_store(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(TopBooksTable::class, ['filters' => []])
            ->assertCanSeeTableRecords([$this->tazarru, $this->kunlar], inOrder: true)
            ->assertTableColumnExists('store_0')
            ->assertTableColumnStateSet('clicks', 3, $this->tazarru->getKey())
            ->assertTableColumnStateSet('visitors', 2, $this->tazarru->getKey())
            // asaxiy is the busiest store, so it is the first store column.
            ->assertTableColumnStateSet('store_0', 2, $this->tazarru->getKey())
            ->assertTableColumnStateSet('clicks', 1, $this->kunlar->getKey());
    }

    public function test_the_filters_narrow_every_count(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(TopBooksTable::class, ['filters' => ['from' => '2026-10-24', 'until' => '2026-10-24']])
            ->assertCanSeeTableRecords([$this->tazarru])
            ->assertCanNotSeeTableRecords([$this->kunlar])
            ->assertTableColumnStateSet('clicks', 2, $this->tazarru->getKey());

        Livewire::test(TopBooksTable::class, ['filters' => ['target' => 'qamar']])
            ->assertCanSeeTableRecords([$this->kunlar])
            ->assertCanNotSeeTableRecords([$this->tazarru]);
    }

    public function test_stores_and_totals(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(TopStoresTable::class, ['filters' => []])
            ->assertSee(['asaxiy', 'book.uz', 'qamar'])
            ->assertDontSee('2026-08-01');

        Livewire::test(ClickStatsOverview::class, ['filters' => []])
            ->assertSee('Clicks to stores')
            ->assertSeeInOrder(['Clicks to stores', '4', 'Visitors', '3', 'Books', '2', 'Busiest store', 'asaxiy']);
    }

    public function test_the_chart_has_a_line_per_store_and_a_point_per_day(): void
    {
        $this->actingAs($this->admin());

        $chart = Livewire::test(ClicksPerDayChart::class, ['filters' => ['from' => '2026-10-22', 'until' => '2026-10-24']])->instance();
        $data = (fn () => $this->getData())->call($chart);

        $this->assertSame(['2026-10-22', '2026-10-23', '2026-10-24'], $data['labels']);
        $this->assertSame(['asaxiy', 'book.uz', 'qamar'], array_column($data['datasets'], 'label'));
        $this->assertSame([0, 0, 2], $data['datasets'][0]['data']);
        $this->assertSame([1, 0, 0], $data['datasets'][2]['data']);
    }

    public function test_a_picked_date_with_a_time_still_covers_its_whole_day(): void
    {
        $this->actingAs($this->admin());

        $chart = Livewire::test(ClicksPerDayChart::class, ['filters' => ['from' => '2026-10-22 11:56:21', 'until' => '2026-10-24 09:00:00']])->instance();
        $data = (fn () => $this->getData())->call($chart);

        $this->assertSame(['2026-10-22', '2026-10-23', '2026-10-24'], $data['labels']);
    }
}
