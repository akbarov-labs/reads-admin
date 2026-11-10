<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Edition;
use App\Models\StoreListing;
use App\Services\Stores\AsaxiyFeed;
use App\Services\Stores\QamarFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Where a book can be bought today: price and stock from Qamar and Asaxiy,
 * kept fresh by re-reading old pages, and shown on the book page while recent.
 */
class StoreStockTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{0: int, 1: string}> url => [status, body] */
    private array $pages = [];

    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.qamar.sitemap' => 'https://qamar.uz/sitemap.xml',
            'services.qamar.delay_ms' => 0,
            'services.qamar.pages_per_run' => 100,
            'services.qamar.refresh_after_hours' => 20,
            'services.asaxiy.delay_ms' => 0,
            'services.asaxiy.pages_per_run' => 100,
            'services.asaxiy.refresh_after_hours' => 20,
        ]);

        Storage::fake('public');
        Storage::fake('local');

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (! str_contains($url, 'qamar.uz') && ! str_contains($url, 'asaxiy.uz')) {
                return Http::response(); // the site revalidation ping
            }

            $this->requested[] = $url;
            [$status, $body] = $this->pages[$url] ?? [404, ''];

            return Http::response($body, $status);
        });
    }

    // ------------------------------------------------------------------
    // Qamar
    // ------------------------------------------------------------------

    public function test_qamar_keeps_whether_the_book_is_in_stock(): void
    {
        $page = $this->ldPage(['@type' => ['Product', 'Book'], 'name' => 'A', 'isbn' => '9789943000041',
            'offers' => ['@type' => 'Offer', 'price' => 65000, 'availability' => 'https://schema.org/OutOfStock']]);

        $this->assertFalse(QamarFeed::parseBook($page)['in_stock']);
        $this->assertTrue(QamarFeed::inStock('https://schema.org/InStock'));
        $this->assertTrue(QamarFeed::inStock('InStock'));
        $this->assertNull(QamarFeed::inStock('https://schema.org/PreOrder'));
        $this->assertNull(QamarFeed::inStock(null));
    }

    public function test_qamar_re_reads_old_pages_for_price_and_stock_and_marks_books_it_dropped(): void
    {
        $this->qamarShop(['a' => [65000, 'InStock'], 'b' => [40000, 'InStock'], 'c' => [30000, 'InStock']]);
        Artisan::call('stores:qamar');

        // A day later: a sold out, its sitemap date unchanged; c gone from the shop.
        $this->travel(25)->hours();
        $this->requested = [];
        $this->qamarShop(['a' => [65000, 'OutOfStock'], 'b' => [42000, 'InStock']]);

        Artisan::call('stores:qamar');

        $this->assertStringContainsString('0 new or changed; read 2 (2 with book details, 2 re-read for price and stock)', Artisan::output());
        $this->assertFalse(StoreListing::where('url', 'https://qamar.uz/kitob/a')->sole()->in_stock);
        $this->assertSame(42000, StoreListing::where('url', 'https://qamar.uz/kitob/b')->sole()->price);
        $this->assertFalse(StoreListing::where('url', 'https://qamar.uz/kitob/c')->sole()->in_stock);

        // The same night again: nothing is old enough to re-read.
        $this->requested = [];
        Artisan::call('stores:qamar');
        $this->assertSame(['https://qamar.uz/sitemap.xml'], $this->requested);
    }

    // ------------------------------------------------------------------
    // Asaxiy
    // ------------------------------------------------------------------

    public function test_an_asaxiy_page_gives_price_stock_and_the_last_few_left(): void
    {
        $offer = AsaxiyFeed::parseOffer($this->asaxiyPage(33000, 'InStock', 'So‘nggi 2 ta qoldi'));
        $this->assertSame(['price' => 33000, 'in_stock' => true, 'stock_left' => 2], $offer);

        $this->assertSame(['price' => 33000, 'in_stock' => false, 'stock_left' => null], AsaxiyFeed::parseOffer($this->asaxiyPage(33000, 'OutOfStock', 'So‘nggi 2 ta qoldi')));
        $this->assertSame(['price' => 50000, 'in_stock' => true, 'stock_left' => null], AsaxiyFeed::parseOffer($this->asaxiyPage(50000, 'InStock')));
        $this->assertNull(AsaxiyFeed::parseOffer('<html><body>Topilmadi</body></html>'));
    }

    public function test_asaxiy_reads_only_our_editions_pages_the_oldest_first_and_never_changes_the_catalogue(): void
    {
        $bor = $this->edition('Bor', 'https://asaxiy.uz/product/bor', ['pages' => null]);
        $tugagan = $this->edition('Tugagan', 'https://asaxiy.uz/product/tugagan');
        $yoq = $this->edition("Yo'q", 'https://asaxiy.uz/product/yoq');
        $this->edition('Softly', 'https://softly.uz/kitob/1');

        $this->pages['https://asaxiy.uz/product/bor'] = [200, $this->asaxiyPage(45000, 'InStock', 'So‘nggi 1 ta qoldi')];
        $this->pages['https://asaxiy.uz/product/tugagan'] = [200, $this->asaxiyPage(30000, 'OutOfStock')];

        $this->assertSame(0, Artisan::call('stores:asaxiy'));
        $this->assertStringContainsString('Read 3 Asaxiy pages: 1 in stock, 1 sold out, 1 gone.', Artisan::output());

        $listing = StoreListing::where('url', 'https://asaxiy.uz/product/bor')->sole();
        $this->assertSame(['asaxiy', $bor->id, 45000, true, 1], [$listing->store, $listing->edition_id, $listing->price, $listing->in_stock, $listing->stock_left]);
        $this->assertFalse(StoreListing::where('edition_id', $tugagan->id)->sole()->in_stock);
        $this->assertFalse(StoreListing::where('edition_id', $yoq->id)->sole()->in_stock);
        $this->assertNull($bor->fresh()->pages);
        $this->assertFalse(collect($this->requested)->contains(fn ($url) => str_contains($url, 'softly')));

        // Read again only once a page is old enough, the oldest first.
        $this->requested = [];
        Artisan::call('stores:asaxiy');
        $this->assertSame([], $this->requested);

        StoreListing::where('url', 'https://asaxiy.uz/product/tugagan')->update(['fetched_at' => now()->subDays(3)]);
        StoreListing::where('url', 'https://asaxiy.uz/product/bor')->update(['fetched_at' => now()->subDays(2)]);
        Artisan::call('stores:asaxiy', ['--limit' => 1]);
        $this->assertSame(['https://asaxiy.uz/product/tugagan'], $this->requested);
    }

    public function test_an_asaxiy_error_ends_the_run(): void
    {
        $this->edition('A', 'https://asaxiy.uz/product/a');
        $this->edition('B', 'https://asaxiy.uz/product/b');
        $this->pages['https://asaxiy.uz/product/a'] = [429, ''];

        Artisan::call('stores:asaxiy');

        $this->assertStringContainsString('Stopped early: Asaxiy: HTTP 429 for https://asaxiy.uz/product/a', Artisan::output());
        $this->assertSame(['https://asaxiy.uz/product/a'], $this->requested);
        $this->assertSame(0, StoreListing::count());
    }

    // ------------------------------------------------------------------
    // The book page
    // ------------------------------------------------------------------

    public function test_a_book_lists_the_stores_that_have_it_in_stock_first_and_only_while_recent(): void
    {
        $book = Book::create(['uzbek_title' => 'Alkimyogar']);
        $old = $book->editions()->create(['isbn' => '9789943000051']);
        $new = $book->editions()->create(['isbn' => '9789943000052']);

        $listing = fn (Edition $edition, string $store, string $url, ?int $price, ?bool $inStock, ?int $left = null, int $daysAgo = 1) => StoreListing::create([
            'store' => $store, 'url' => $url, 'edition_id' => $edition->id, 'isbn' => $edition->isbn,
            'price' => $price, 'in_stock' => $inStock, 'stock_left' => $left, 'fetched_at' => now()->subDays($daysAgo),
        ]);

        $listing($old, 'qamar', 'https://qamar.uz/kitob/eski', 50000, false);
        $listing($new, 'qamar', 'https://qamar.uz/kitob/yangi', 70000, true);
        $listing($old, 'asaxiy', 'https://asaxiy.uz/product/alkimyogar', 62000, true, 1);
        $listing($new, 'asaxiy', 'https://asaxiy.uz/product/eski-narx', 10000, true, null, 30);
        $listing($new, 'softly', 'https://softly.uz/x', 1000, true);

        $stores = $this->getJson("/api/books/{$book->id}")->assertOk()->json('data.stores');

        $this->assertSame(['asaxiy', 'qamar'], array_column($stores, 'store'));
        $this->assertSame(['Asaxiy', 'https://asaxiy.uz/product/alkimyogar', 62000, true, 1], [$stores[0]['name'], $stores[0]['url'], $stores[0]['price'], $stores[0]['inStock'], $stores[0]['left']]);
        $this->assertSame(['https://qamar.uz/kitob/yangi', 70000, true, null], [$stores[1]['url'], $stores[1]['price'], $stores[1]['inStock'], $stores[1]['left']]);

        StoreListing::query()->update(['fetched_at' => now()->subDays(11)]);
        $this->assertSame([], $this->getJson("/api/books/{$book->id}")->json('data.stores'));
    }

    // ------------------------------------------------------------------

    /** @param  array<string, array{0: int, 1: string}>  $books  slug => [price, availability] */
    private function qamarShop(array $books): void
    {
        $xml = '<?xml version="1.0"?><urlset>';
        $this->pages = [];

        foreach ($books as $slug => [$price, $availability]) {
            $url = "https://qamar.uz/kitob/{$slug}";
            $xml .= "<url><loc>{$url}</loc><lastmod>2026-11-01T00:00:00Z</lastmod></url>";
            $this->pages[$url] = [200, $this->ldPage(['@type' => ['Product', 'Book'], 'name' => $slug, 'isbn' => '97899430000'.str_pad((string) (ord($slug) - 90), 2, '0', STR_PAD_LEFT),
                'offers' => ['@type' => 'Offer', 'price' => $price, 'availability' => "https://schema.org/{$availability}"]])];
        }

        $this->pages['https://qamar.uz/sitemap.xml'] = [200, $xml.'</urlset>'];
    }

    /** @param  array<string, mixed>  $node */
    private function ldPage(array $node): string
    {
        $json = json_encode(['@context' => 'https://schema.org', ...$node], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "<html><head><script type=\"application/ld+json\">{$json}</script></head><body></body></html>";
    }

    private function asaxiyPage(int $price, string $availability, ?string $note = null): string
    {
        $page = $this->ldPage(['@type' => 'Product', 'name' => 'Kitob', 'sku' => '4823',
            'offers' => ['@type' => 'Offer', 'price' => (string) $price, 'priceCurrency' => 'UZS', 'availability' => "https://schema.org/{$availability}"]]);

        return str_replace('<body></body>', '<body><div class="text__content-name"> ● '.($note ?? 'Mavjud').' <a id="add_to_cart">Savatga</a></div></body>', $page);
    }

    /** @param  array<string, mixed>  $attributes */
    private function edition(string $title, string $sourceUrl, array $attributes = []): Edition
    {
        $book = Book::create(['uzbek_title' => $title]);

        return $book->editions()->create(['title' => $title, 'source_url' => $sourceUrl, 'isbn' => '97899430'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT), ...$attributes]);
    }
}
