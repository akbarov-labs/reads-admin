<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\Library;
use App\Models\SoftlyBook;
use App\Services\Softly\SoftlyClient;
use App\Services\Softly\SoftlySync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Softly's book list (kutubxonam.uz) to library holdings, without asking it for much. */
class SoftlySyncTest extends TestCase
{
    use RefreshDatabase;

    /** What Softly answers for Mehr (library 1), newest change first, as one list it pages through. */
    private array $softly = [];

    /** The same for other Softly libraries, by library id. */
    private array $otherLibraries = [];

    private ?int $failWithStatus = null;

    private Library $mehr;

    private Book $kunlar;

    private Book $chayon;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.softly.enabled' => true,
            'services.softly.libraries' => [1],
            'services.softly.create_books' => false,
            'services.softly.page_size' => 2,
            'services.softly.delay_ms' => 0,
            'services.softly.daily_cap' => 100,
            'services.softly.quick_mode' => 'changed',
        ]);

        $this->mehr = Library::create(['slug' => 'mehr', 'name' => 'Mehr', 'region' => 'Toshkent shahri', 'softly_location_id' => 1]);

        $qodiriy = Author::create(['name' => 'Abdulla Qodiriy', 'slug' => 'abdulla-qodiriy']);
        $this->kunlar = Book::create(['uzbek_title' => "O'tkan kunlar"]);
        $this->kunlar->authors()->attach($qodiriy);
        $this->kunlar->editions()->create([]);
        $this->chayon = Book::create(['uzbek_title' => 'Mehrobdan chayon']);
        $this->chayon->authors()->attach($qodiriy);
        $this->chayon->editions()->create([]);

        Http::fake(function (Request $request) {
            if (! str_contains($request->url(), 'softly')) {
                return Http::response();
            }

            if ($this->failWithStatus !== null) {
                return Http::response('', $this->failWithStatus);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $size = (int) $query['size'];
            // Like Softly: no header means Mehr.
            $library = (int) ($request->header('library')[0] ?? 1);
            $list = $library === 1 ? $this->softly : ($this->otherLibraries[$library] ?? []);

            return Http::response([
                'page' => (int) $query['page'],
                'items' => array_slice($list, ((int) $query['page'] - 1) * $size, $size),
                'totalCount' => count($list),
            ]);
        });
    }

    private function item(int $id, string $name, string $author, string $updatedAt, array $stocks): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'image' => null,
            'description' => null,
            'updatedAt' => $updatedAt,
            'deletedAt' => null,
            'stocks' => array_map(fn (array $stock) => ['id' => $stock[0], 'busy' => $stock[1], 'locationId' => $stock[2]], $stocks),
            'author' => ['name' => $author],
            'collection' => null,
        ];
    }

    private function sync(bool $full = false): array
    {
        return app(SoftlySync::class)->run($full);
    }

    private function softlyRequests(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'softly'))->count();
    }

    private function holding(Book $book): ?array
    {
        $holding = $this->mehr->holdings()->where('book_id', $book->id)->first();

        return $holding ? [$holding->copies_total, $holding->copies_available] : null;
    }

    public function test_first_run_reads_everything_and_fills_the_linked_library(): void
    {
        $this->softly = [
            $this->item(3, "O'tkan kunlar", 'Abdulla Qodiriy', '2026-10-27T06:00:00.250Z', [[30, true, 1], [31, false, 1], [32, false, 16]]),
            $this->item(2, 'Mehrobdan chayon', 'Qodiriy A.', '2026-10-26T06:00:00.000Z', [[20, true, 1]]),
            $this->item(1, 'Harvard metodi', 'Kimdir', '2026-10-25T06:00:00.000Z', [[10, false, 1]]),
        ];

        $report = $this->sync();

        $this->assertSame('full', $report['mode']);
        $this->assertSame(2, $report['requests']);
        $this->assertSame(3, $report['added']);
        $this->assertSame(2, $report['matched']);
        $this->assertTrue($report['complete']);

        // Two copies at Mehr, one out; the third is at another location.
        $this->assertSame([2, 1], $this->holding($this->kunlar));
        $this->assertSame([1, 0], $this->holding($this->chayon));
        $this->assertSame(2, $this->mehr->holdings()->count());

        $unmatched = SoftlyBook::where('softly_id', 1)->first();
        $this->assertNull($unmatched->book_id);
        $this->assertSame([1 => ['copies' => 4, 'library' => 'mehr'], 16 => ['copies' => 1, 'library' => null]], $report['locations']);
        $this->assertNotNull($this->mehr->fresh()->holdings_updated_at);
    }

    public function test_a_quick_run_stops_at_the_last_change_it_saw(): void
    {
        $this->softly = [
            $this->item(3, "O'tkan kunlar", 'Abdulla Qodiriy', '2026-10-27T06:00:00.000Z', [[30, false, 1]]),
            $this->item(2, 'Mehrobdan chayon', 'Abdulla Qodiriy', '2026-10-26T06:00:00.000Z', [[20, false, 1]]),
            $this->item(1, 'Harvard metodi', 'Kimdir', '2026-10-25T06:00:00.000Z', [[10, false, 1]]),
        ];
        $this->sync();
        $before = $this->softlyRequests();

        // A copy of O'tkan kunlar goes out, and its book moves to the top.
        $this->softly[0] = $this->item(3, "O'tkan kunlar", 'Abdulla Qodiriy', '2026-10-27T09:00:00.000Z', [[30, true, 1]]);

        $report = $this->sync();

        $this->assertSame('changed', $report['mode']);
        $this->assertSame(1, $this->softlyRequests() - $before);
        $this->assertSame(1, $report['changed']);
        $this->assertSame([1, 0], $this->holding($this->kunlar));
        $this->assertSame([1, 1], $this->holding($this->chayon));
    }

    public function test_a_full_run_takes_away_what_softly_no_longer_lists(): void
    {
        $this->softly = [
            $this->item(3, "O'tkan kunlar", 'Abdulla Qodiriy', '2026-10-27T06:00:00.000Z', [[30, false, 1]]),
            $this->item(2, 'Mehrobdan chayon', 'Abdulla Qodiriy', '2026-10-26T06:00:00.000Z', [[20, false, 1]]),
        ];
        $this->sync();

        array_pop($this->softly);
        $report = $this->sync(full: true);

        $this->assertSame(1, $report['removed']);
        $this->assertNull($this->holding($this->chayon));
        $this->assertSame([1, 1], $this->holding($this->kunlar));
    }

    public function test_a_linked_library_list_comes_from_softly_only(): void
    {
        $this->mehr->holdings()->create(['book_id' => $this->chayon->id]);
        $this->softly = [$this->item(3, "O'tkan kunlar", 'Abdulla Qodiriy', '2026-10-27T06:00:00.000Z', [[30, false, 1]])];

        $this->sync();

        $this->assertNull($this->holding($this->chayon));
        $this->assertSame([1, 1], $this->holding($this->kunlar));
    }

    public function test_an_error_pauses_every_request_and_takes_nothing_away(): void
    {
        $this->softly = [
            $this->item(3, "O'tkan kunlar", 'Abdulla Qodiriy', '2026-10-27T06:00:00.000Z', [[30, false, 1]]),
            $this->item(2, 'Mehrobdan chayon', 'Abdulla Qodiriy', '2026-10-26T06:00:00.000Z', [[20, false, 1]]),
        ];
        $this->sync();

        $this->failWithStatus = 503;
        $report = $this->sync(full: true);

        $this->assertFalse($report['complete']);
        $this->assertStringContainsString('HTTP 503', $report['error']);
        $this->assertSame(0, $report['removed']);
        $this->assertSame([1, 1], $this->holding($this->chayon));
        $this->assertNotNull(app(SoftlyClient::class)->pausedUntil());

        // Softly is back, but the pause holds: not one request.
        $this->failWithStatus = null;
        $before = $this->softlyRequests();
        $report = $this->sync();

        $this->assertStringContainsString('Paused', $report['error']);
        $this->assertSame($before, $this->softlyRequests());

        $this->travel(61)->minutes();
        $this->assertTrue($this->sync()['complete']);
    }

    public function test_no_request_once_the_daily_cap_is_spent(): void
    {
        config(['services.softly.daily_cap' => 1]);
        $this->softly = [
            $this->item(3, "O'tkan kunlar", 'Abdulla Qodiriy', '2026-10-27T06:00:00.000Z', [[30, false, 1]]),
            $this->item(2, 'Mehrobdan chayon', 'Abdulla Qodiriy', '2026-10-26T06:00:00.000Z', [[20, false, 1]]),
            $this->item(1, 'Harvard metodi', 'Kimdir', '2026-10-25T06:00:00.000Z', [[10, false, 1]]),
        ];

        $report = $this->sync();

        $this->assertSame(1, $this->softlyRequests());
        $this->assertStringContainsString('cap', $report['error']);
        // The cap is not an error on Softly's side: no pause.
        $this->assertNull(app(SoftlyClient::class)->pausedUntil());
    }

    public function test_the_nightly_run_counts_status_changes_a_quick_run_cannot_see(): void
    {
        $this->softly = [
            $this->item(3, 'Mehrobdan chayon', 'Abdulla Qodiriy', '2026-10-27T06:00:00.123Z', [[30, false, 1]]),
            $this->item(2, "O'tkan kunlar", 'Abdulla Qodiriy', '2026-10-26T06:00:00.123Z', [[20, false, 1]]),
        ];
        $this->sync();

        // Out, with the book's updatedAt left as it was.
        $this->softly[1]['stocks'][0]['busy'] = true;

        $this->assertSame(0, $this->sync()['changed']);
        $this->assertSame([1, 1], $this->holding($this->kunlar));

        $report = $this->sync(full: true);

        $this->assertSame(1, $report['missed_by_quick']);
        $this->assertSame([1, 0], $this->holding($this->kunlar));
    }

    public function test_each_library_is_read_with_its_own_header(): void
    {
        config(['services.softly.libraries' => [1, 16]]);
        $kitobqand = Library::create(['slug' => 'kitobqand', 'name' => 'Kitobqand', 'region' => 'Toshkent shahri', 'softly_location_id' => 16]);

        $this->softly = [$this->item(3, "O'tkan kunlar", 'Abdulla Qodiriy', '2026-10-27T06:00:00.000Z', [[30, false, 1]])];
        // The same Softly id in another library is another book there.
        $this->otherLibraries[16] = [
            $this->item(3, 'Mehrobdan chayon', 'Abdulla Qodiriy', '2026-10-26T06:00:00.000Z', [[40, true, 16], [41, false, 16]]),
        ];

        $report = $this->sync();

        $this->assertSame(2, $report['requests']);
        $this->assertSame(2, $report['added']);
        $this->assertSame(['1', '16'], Http::recorded(fn (Request $request) => str_contains($request->url(), 'softly'))
            ->map(fn (array $pair) => $pair[0]->header('library')[0])->all());

        $this->assertSame([1, 1], $this->holding($this->kunlar));
        $this->assertNull($this->holding($this->chayon));
        $this->assertSame([2, 1], [
            $kitobqand->holdings()->where('book_id', $this->chayon->id)->value('copies_total'),
            $kitobqand->holdings()->where('book_id', $this->chayon->id)->value('copies_available'),
        ]);
    }

    public function test_a_quick_run_stops_at_each_library_s_own_last_change(): void
    {
        config(['services.softly.libraries' => [1, 16]]);
        Library::create(['slug' => 'kitobqand', 'name' => 'Kitobqand', 'region' => 'Toshkent shahri', 'softly_location_id' => 16]);

        $this->softly = [$this->item(3, "O'tkan kunlar", 'Abdulla Qodiriy', '2026-10-27T06:00:00.000Z', [[30, false, 1]])];
        $this->otherLibraries[16] = [
            $this->item(8, 'Mehrobdan chayon', 'Abdulla Qodiriy', '2026-10-20T06:00:00.000Z', [[40, false, 16]]),
            $this->item(7, 'Harvard metodi', 'Kimdir', '2026-10-19T06:00:00.000Z', [[41, false, 16]]),
        ];
        $this->sync();

        // Out at Kitobqand, on a day older than Mehr's newest change.
        $this->otherLibraries[16][0] = $this->item(8, 'Mehrobdan chayon', 'Abdulla Qodiriy', '2026-10-21T06:00:00.000Z', [[40, true, 16]]);

        $report = $this->sync();

        $this->assertSame('changed', $report['mode']);
        $this->assertSame(1, $report['changed']);
        $this->assertSame(0, (int) Library::where('slug', 'kitobqand')->first()->holdings()->value('copies_available'));
    }

    public function test_a_new_library_is_read_in_full_on_a_quick_run(): void
    {
        $this->softly = [$this->item(3, "O'tkan kunlar", 'Abdulla Qodiriy', '2026-10-27T06:00:00.000Z', [[30, false, 1]])];
        $this->sync();

        config(['services.softly.libraries' => [1, 16]]);
        $this->otherLibraries[16] = [
            $this->item(8, 'Mehrobdan chayon', 'Abdulla Qodiriy', '2026-10-20T06:00:00.000Z', [[40, false, 16]]),
            $this->item(7, 'Harvard metodi', 'Kimdir', '2026-10-19T06:00:00.000Z', [[41, false, 16]]),
            $this->item(6, 'Kecha va kunduz', "Cho'lpon", '2026-10-18T06:00:00.000Z', [[42, false, 16]]),
        ];

        $report = $this->sync();

        $this->assertSame('changed', $report['mode']);
        $this->assertSame(3, $report['added']);
        $this->assertSame(3, SoftlyBook::where('softly_library', 16)->count());
    }

    public function test_a_full_run_takes_away_only_what_that_library_no_longer_lists(): void
    {
        config(['services.softly.libraries' => [1, 16]]);
        $this->softly = [$this->item(3, "O'tkan kunlar", 'Abdulla Qodiriy', '2026-10-27T06:00:00.000Z', [[30, false, 1]])];
        $this->otherLibraries[16] = [
            $this->item(8, 'Mehrobdan chayon', 'Abdulla Qodiriy', '2026-10-20T06:00:00.000Z', [[40, false, 16]]),
            $this->item(3, 'Harvard metodi', 'Kimdir', '2026-10-19T06:00:00.000Z', [[41, false, 16]]),
        ];
        $this->sync();

        array_pop($this->otherLibraries[16]);
        $report = $this->sync(full: true);

        $this->assertSame(1, $report['removed']);
        $this->assertTrue(SoftlyBook::where('softly_library', 1)->where('softly_id', 3)->exists());
        $this->assertFalse(SoftlyBook::where('softly_library', 16)->where('softly_id', 3)->exists());

        // Taken out of SOFTLY_LIBRARIES: its books go on the next full run.
        config(['services.softly.libraries' => [1]]);
        $this->assertSame(1, $this->sync(full: true)['removed']);
        $this->assertSame(0, SoftlyBook::where('softly_library', 16)->count());
    }

    public function test_a_full_run_makes_books_of_titles_with_nothing_like_them(): void
    {
        config(['services.softly.create_books' => true]);
        $this->softly = [$this->item(3, "O'tkan kunlar", 'Abdulla Qodiriy', '2026-10-27T06:00:00.000Z', [[30, false, 1]])];
        $this->sync();

        // A quick run that adds a title leaves it for the night.
        array_unshift($this->softly, $this->item(1, 'Harvard metodi', 'Kimdir', '2026-10-27T08:00:00.000Z', [[10, true, 1], [11, false, 1]]));
        $this->assertSame(0, $this->sync()['created']);
        $this->assertSame(0, Book::where('uzbek_title', 'Harvard metodi')->count());

        $report = $this->sync(full: true);

        $this->assertSame(1, $report['created']);
        $harvard = Book::where('uzbek_title', 'Harvard metodi')->sole();
        $this->assertSame([2, 1], $this->holding($harvard));
    }

    public function test_the_command_sends_nothing_while_the_sync_is_off(): void
    {
        config(['services.softly.enabled' => false]);

        $this->artisan('softly:sync')->assertSuccessful();

        $this->assertSame(0, $this->softlyRequests());
    }
}
