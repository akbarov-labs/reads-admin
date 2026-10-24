<?php

namespace Tests\Feature\Api;

use App\Enums\OutboundClickKind;
use App\Models\Book;
use App\Models\OutboundClick;
use App\Support\SiteStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The site's /go/store/... redirect logs each reader it sends to a store
 * here, so stores and publishers can be shown what Kitoblarim sends them.
 */
class OutboundClickApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'site-shared-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['seo.revalidate.secret' => self::SECRET]);
    }

    private function click(array $overrides = [], ?string $token = self::SECRET)
    {
        $book = Book::firstOrCreate(['uzbek_title' => "O'tkan kunlar"]);

        $request = $token === null ? $this : $this->withToken($token);

        return $request->postJson('/api/clicks', [
            'kind' => 'store',
            'book_id' => $book->id,
            'target' => 'asaxiy',
            'locale' => 'uz',
            'ip' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0 (iPhone)',
            ...$overrides,
        ]);
    }

    public function test_the_site_logs_a_store_click(): void
    {
        $this->click()->assertNoContent();

        $click = OutboundClick::sole();
        $this->assertSame(OutboundClickKind::Store, $click->kind);
        $this->assertSame('asaxiy', $click->target);
        $this->assertSame('uz', $click->locale);
        $this->assertSame(Book::sole()->id, $click->book_id);
        $this->assertNotNull($click->created_at);
    }

    public function test_no_address_is_stored_only_a_keyed_hash(): void
    {
        $this->click()->assertNoContent();

        $row = (array) DB::table('outbound_clicks')->first();
        $this->assertSame(64, strlen($row['visitor_hash']));
        $this->assertStringNotContainsString('203.0.113.9', implode('|', array_map('strval', $row)));
        $this->assertNotSame(hash('sha256', '203.0.113.9'), $row['visitor_hash']);
    }

    public function test_one_visitor_hashes_the_same_all_day_and_differently_the_next(): void
    {
        $morning = CarbonImmutable::parse('2026-10-24 06:00', 'Asia/Tashkent');

        $a = SiteStats::visitorHash('203.0.113.9', 'UA', $morning);
        $b = SiteStats::visitorHash('203.0.113.9', 'UA', $morning->setTime(23, 30));
        $c = SiteStats::visitorHash('203.0.113.9', 'UA', $morning->addDay());
        $d = SiteStats::visitorHash('203.0.113.10', 'UA', $morning);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertNotSame($a, $d);
    }

    public function test_without_the_sites_token_nothing_is_logged(): void
    {
        $this->click([], null)->assertUnauthorized();
        $this->click([], 'wrong')->assertUnauthorized();

        $this->assertSame(0, OutboundClick::count());
    }

    public function test_an_unset_secret_is_not_an_open_door(): void
    {
        config(['seo.revalidate.secret' => '']);

        $this->click([], '')->assertStatus(503);
        $this->assertSame(0, OutboundClick::count());
    }

    public function test_junk_is_rejected(): void
    {
        $this->click(['kind' => 'casino'])->assertUnprocessable();
        $this->click(['target' => 'https://evil.example'])->assertUnprocessable();
        $this->click(['book_id' => 999999])->assertUnprocessable();
        $this->click(['locale' => 'de'])->assertUnprocessable();

        $this->assertSame(0, OutboundClick::count());
    }

    public function test_one_visitor_cannot_inflate_a_count(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->click()->assertNoContent();
        }

        $this->assertSame(30, OutboundClick::count());

        // Someone else is still counted.
        $this->click(['ip' => '198.51.100.7'])->assertNoContent();
        $this->assertSame(31, OutboundClick::count());
    }

    public function test_days_are_tashkent_days(): void
    {
        $book = Book::create(['uzbek_title' => 'Kecha va kunduz']);

        // 20:30 UTC is 01:30 the next day in Tashkent.
        OutboundClick::create(['kind' => 'store', 'book_id' => $book->id, 'target' => 'qamar', 'created_at' => '2026-10-23 20:30:00']);
        OutboundClick::create(['kind' => 'store', 'book_id' => $book->id, 'target' => 'qamar', 'created_at' => '2026-10-23 18:30:00']);

        $this->assertSame(1, OutboundClick::betweenDays('2026-10-24', '2026-10-24')->count());
        $this->assertSame(
            ['2026-10-23', '2026-10-24'],
            OutboundClick::query()->selectRaw(SiteStats::daySql().' as day')->orderBy('day')->pluck('day')->all(),
        );
    }
}
