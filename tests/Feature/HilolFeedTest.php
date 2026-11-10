<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Publisher;
use App\Models\StoreListing;
use App\Services\Stores\HilolFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * hilolnashr.uz: price and stock from its listing each night, ISBNs from
 * each book's page once, and "nashriyot" on its own books.
 */
class HilolFeedTest extends TestCase
{
    use RefreshDatabase;

    private const LISTING = 'https://hilolnashr.uz/index.php?route=product/category&path=37';

    /** @var array<string, array{0: int, 1: string}> */
    private array $pages = [];

    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.hilol.listing' => self::LISTING,
            'services.hilol.delay_ms' => 0,
            'services.hilol.listing_pages_per_run' => 50,
            'services.hilol.book_pages_per_run' => 50,
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (! str_contains($url, 'hilolnashr.uz')) {
                return Http::response(); // the site revalidation ping
            }

            $this->requested[] = $url;
            [$status, $body] = $this->pages[$url] ?? [404, ''];

            return Http::response($body, $status);
        });
    }

    public function test_a_listing_page_gives_its_own_grid_and_not_the_side_modules(): void
    {
        $html = $this->listing([
            ['qalb', '«Qalb ko‘zi»', '<span class="price-normal">58 000 сўм</span>', false],
            ['din', '«Дин – насиҳатдир»', '<span class="price-new">1 200 сўм</span> <span class="price-old">1 400 сўм</span>', true],
        ], pages: 2, side: true);

        $this->assertSame([
            ['url' => 'https://hilolnashr.uz/kitoblar/qalb', 'title' => '«Qalb ko‘zi»', 'price' => 58000, 'in_stock' => true],
            ['url' => 'https://hilolnashr.uz/kitoblar/din', 'title' => '«Дин – насиҳатдир»', 'price' => 1200, 'in_stock' => false],
        ], HilolFeed::parseListing($html));
        $this->assertSame(2, HilolFeed::pageCount($html));

        // Past the last page: only side modules, no pagination row.
        $this->assertSame([], HilolFeed::parseListing('<div class="main-products product-grid"></div>'.$this->sideModule()));

        $this->assertSame('9789910099687', HilolFeed::parseIsbn('<p>Ҳажми : 629 бет<br>ISBN : 978-9910-09-968-7</p><script>var x = "<b>";</script>'));
        $this->assertNull(HilolFeed::parseIsbn('<p>ISBN: йўқ</p>'));
    }

    public function test_a_night_walks_the_listing_reads_new_books_isbns_once_and_matches_them(): void
    {
        $book = Book::create(['uzbek_title' => 'Qalb ko‘zi']);
        $edition = $book->editions()->create(['isbn' => '9789910099687']);

        $this->shop(page1: [['qalb', 'Qalb', '58 000 сўм', false], ['din', 'Din', '1 400 сўм', true]], page2: [['ilm', 'Ilm', '30 000 сўм', false]]);
        $this->pages['https://hilolnashr.uz/kitoblar/qalb'] = [200, '<p>ISBN: 978-9910-09-968-7</p>'];
        $this->pages['https://hilolnashr.uz/kitoblar/din'] = [200, '<p>ISBN: 978-9943-00-000-1</p>'];
        $this->pages['https://hilolnashr.uz/kitoblar/ilm'] = [200, '<p>Muallif: X</p>'];

        $this->assertSame(0, Artisan::call('stores:hilol'));
        $this->assertStringContainsString('Listing: 2 pages, 3 books (to its end). Book pages read for ISBN: 3. Newly matched with the catalogue: 1.', Artisan::output());

        $qalb = StoreListing::where('url', 'https://hilolnashr.uz/kitoblar/qalb')->sole();
        $this->assertSame(['hilol-nashr', $edition->id, 58000, true], [$qalb->store, $qalb->edition_id, $qalb->price, $qalb->in_stock]);
        $this->assertFalse(StoreListing::where('url', 'https://hilolnashr.uz/kitoblar/din')->sole()->in_stock);
        $this->assertNull(StoreListing::where('url', 'https://hilolnashr.uz/kitoblar/ilm')->sole()->isbn);
        // It stopped at the stated last page and never asked for page 3.
        $this->assertNotContains(self::LISTING.'&page=3', $this->requested);

        // The next night: the listing again, no book page twice; a book gone from it is sold out.
        $this->requested = [];
        $this->travel(1)->days();
        $this->shop(page1: [['qalb', 'Qalb', '60 000 сўм', false]], page2: [['ilm', 'Ilm', '30 000 сўм', false]]);

        Artisan::call('stores:hilol');

        $this->assertSame([self::LISTING.'&page=1', self::LISTING.'&page=2'], $this->requested);
        $this->assertSame(60000, $qalb->fresh()->price);
        $this->assertFalse(StoreListing::where('url', 'https://hilolnashr.uz/kitoblar/din')->sole()->in_stock);
    }

    public function test_an_error_ends_the_night_and_keeps_what_was_read(): void
    {
        $this->shop(page1: [['qalb', 'Qalb', '58 000 сўм', false]], page2: []);
        $this->pages[self::LISTING.'&page=2'] = [429, ''];

        Artisan::call('stores:hilol');

        $this->assertStringContainsString('Stopped early: Hilol: HTTP 429', Artisan::output());
        $this->assertSame(1, StoreListing::count());
        // Not walked to its end: nothing is marked sold out for being missing.
        $this->assertTrue(StoreListing::sole()->in_stock);
    }

    public function test_the_book_page_says_nashriyot_only_for_the_publishers_own_book(): void
    {
        $hilol = Publisher::create(['name' => 'Hilol-Nashr', 'slug' => 'hilol-nashr']);
        $other = Publisher::create(['name' => 'Noma', 'slug' => 'noma']);
        $own = Book::create(['uzbek_title' => 'Tafsiri Hilol']);
        $ownEdition = $own->editions()->create(['isbn' => '9789943000061', 'publisher_id' => $hilol->id]);
        $theirs = Book::create(['uzbek_title' => 'Insoniyat yulduzlari']);
        $theirsEdition = $theirs->editions()->create(['isbn' => '9789943000062', 'publisher_id' => $other->id]);

        foreach ([[$ownEdition, 'tafsir'], [$theirsEdition, 'yulduzlar']] as [$edition, $slug]) {
            StoreListing::create(['store' => 'hilol-nashr', 'url' => "https://hilolnashr.uz/kitoblar/{$slug}", 'edition_id' => $edition->id,
                'price' => 100000, 'in_stock' => true, 'fetched_at' => now()]);
        }

        $this->assertTrue($this->getJson("/api/books/{$own->id}")->json('data.stores.0.fromPublisher'));
        $this->assertSame('Hilol-nashr', $this->getJson("/api/books/{$own->id}")->json('data.stores.0.name'));
        $this->assertFalse($this->getJson("/api/books/{$theirs->id}")->json('data.stores.0.fromPublisher'));
    }

    // ------------------------------------------------------------------

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: bool}>  $page1  [slug, title, price, sold out]
     * @param  list<array{0: string, 1: string, 2: string, 3: bool}>  $page2
     */
    private function shop(array $page1, array $page2): void
    {
        $wrap = fn (array $books) => array_map(fn ($b) => [$b[0], $b[1], "<span class=\"price-normal\">{$b[2]}</span>", $b[3]], $books);

        $this->pages[self::LISTING.'&page=1'] = [200, $this->listing($wrap($page1), pages: 2)];
        $this->pages[self::LISTING.'&page=2'] = [200, $this->listing($wrap($page2), pages: 2)];
    }

    /** @param  list<array{0: string, 1: string, 2: string, 3: bool}>  $books */
    private function listing(array $books, int $pages, bool $side = false): string
    {
        $items = '';

        foreach ($books as [$slug, $title, $price, $soldOut]) {
            $class = $soldOut ? ' out-of-stock has-extra-button' : ' has-extra-button';
            $items .= "<div class=\"product-layout{$class}\"><div class=\"product-thumb\"><div class=\"caption\">"
                ."<div class=\"name\"><a href=\"https://hilolnashr.uz/kitoblar/{$slug}\">{$title}</a></div>"
                ."<div class=\"price\"> <div> {$price} </div> </div></div></div></div>";
        }

        return '<html><body>'.($side ? $this->sideModule() : '')
            ."<div class=\"main-products product-grid\">{$items}</div>"
            ."<div class=\"row pagination-results\"><div>Жами 99 та ({$pages} саҳифада)</div></div>"
            .'</body></html>';
    }

    private function sideModule(): string
    {
        return '<div class="module-products"><div class="product-layout"><div class="name"><a href="https://hilolnashr.uz/side">Side</a></div>'
            .'<div class="price"> <div> 5 000 сўм </div> </div></div></div>';
    }
}
