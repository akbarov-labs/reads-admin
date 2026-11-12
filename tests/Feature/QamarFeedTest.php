<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\StoreListingResource\Pages\ListStoreListings;
use App\Models\Edition;
use App\Models\StoreListing;
use App\Models\User;
use App\Services\Stores\QamarFeed;
use App\Services\Stores\StoreListingApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * qamar.uz as a source: its sitemap and JSON-LD read politely, and only
 * catalogue blanks filled from it, by ISBN.
 */
class QamarFeedTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    /** @var array<string, string> url => page HTML */
    private array $pages = [];

    private string $sitemap = '';

    private ?int $failWithStatus = null;

    /** Serve covers as a picture far past MAX_COVER_PIXELS. */
    private bool $huge = false;

    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.qamar.sitemap' => 'https://qamar.uz/sitemap.xml',
            'services.qamar.delay_ms' => 0,
            'services.qamar.pages_per_run' => 100,
        ]);

        Storage::fake('public');
        Storage::fake('local');

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (! str_contains($url, 'qamar.uz')) {
                return Http::response(); // the site revalidation ping
            }

            $this->requested[] = $url;

            if ($this->failWithStatus !== null && $url !== config('services.qamar.sitemap')) {
                return Http::response('', $this->failWithStatus);
            }

            if ($url === config('services.qamar.sitemap')) {
                return Http::response($this->sitemap);
            }

            if (str_contains($url, '/media/')) {
                return Http::response($this->huge ? $this->hugePngHeader() : $this->png());
            }

            return isset($this->pages[$url]) ? Http::response($this->pages[$url]) : Http::response('', 404);
        });
    }

    // ------------------------------------------------------------------
    // Reading the shop
    // ------------------------------------------------------------------

    public function test_the_sitemap_gives_book_pages_and_their_dates(): void
    {
        $pages = QamarFeed::parseSitemap($this->sitemapXml([
            'https://qamar.uz/' => null,
            'https://qamar.uz/kitob/baxtli-oila' => '2026-11-09T13:32:07.226Z',
            'https://qamar.uz/muallif/abdulla-qodiriy' => '2026-11-09T13:32:07.226Z',
            'https://qamar.uz/kitob/otkan-kunlar' => null,
        ]));

        $this->assertSame(['https://qamar.uz/kitob/baxtli-oila', 'https://qamar.uz/kitob/otkan-kunlar'], array_keys($pages));
        $this->assertSame('2026-11-09 13:32:07', $pages['https://qamar.uz/kitob/baxtli-oila']->format('Y-m-d H:i:s'));
        $this->assertNull($pages['https://qamar.uz/kitob/otkan-kunlar']);
    }

    public function test_a_book_page_gives_its_json_ld_book_and_nothing_of_its_text(): void
    {
        $book = QamarFeed::parseBook($this->bookPage([
            'name' => 'Baxtli oila',
            'isbn' => '978-9943-5679-9-3',
            'author' => ['@type' => 'Person', 'name' => 'Shayx Abulaziz Mansur'],
            'publisher' => ['@type' => 'Organization', 'name' => 'Bio-factor press'],
            'numberOfPages' => '544',
            'inLanguage' => 'uz',
            'image' => 'https://qamar.uz/media/1c/baxtli-oila.jpg',
            'description' => 'Mazkur kitob mustahkam oila haqida.',
            'offers' => ['@type' => 'Offer', 'price' => 68000],
        ]));

        $this->assertSame([
            'isbn' => '9789943567993',
            'title' => 'Baxtli oila',
            'author' => 'Shayx Abulaziz Mansur',
            'publisher' => 'Bio-factor press',
            'pages' => 544,
            'language' => 'uz',
            'price' => 68000,
            'in_stock' => null,
            'image_url' => 'https://qamar.uz/media/1c/baxtli-oila.jpg',
        ], $book);

        $this->assertNull(QamarFeed::parseBook('<html><script type="application/ld+json">{"@type":"Organization"}</script></html>'));
    }

    // ------------------------------------------------------------------
    // stores:qamar
    // ------------------------------------------------------------------

    public function test_it_fills_blanks_by_isbn_notes_differences_and_lists_books_new_to_us(): void
    {
        $bare = $this->catalogueBook(
            book: ['uzbek_title' => 'Baxtli oila'],
            edition: ['isbn' => '9789943567993', 'pages' => null, 'cover_path' => null, 'sync_hash' => 'bot-has-this'],
            authors: [],
        );
        $bare->editions()->first()->forceFill(['publisher_id' => $this->publisher('Imom Buxoriy xalqaro markazi')->id])->save();
        $covered = $this->catalogueBook(
            book: ['uzbek_title' => "O'tkan kunlar"],
            edition: ['isbn' => '9789943000001', 'pages' => 300, 'cover_path' => 'covers/ours.webp'],
            authors: [$this->author('Abdulla Qodiriy')],
        );

        $this->shop([
            'https://qamar.uz/kitob/baxtli-oila' => ['name' => 'Baxtli oila', 'isbn' => '9789943567993', 'author' => ['name' => 'Shayx Abulaziz Mansur'], 'publisher' => ['name' => 'Bio-factor press'], 'numberOfPages' => 544, 'image' => 'https://qamar.uz/media/baxtli.jpg'],
            'https://qamar.uz/kitob/otkan-kunlar' => ['name' => "O'tkan kunlar", 'isbn' => '9789943000001', 'author' => ['name' => 'Abdulla Qodiriy'], 'numberOfPages' => 302, 'image' => 'https://qamar.uz/media/kunlar.jpg'],
            'https://qamar.uz/kitob/yangi-kitob' => ['name' => 'Yangi kitob', 'isbn' => '9789943999999', 'image' => 'https://qamar.uz/media/yangi.jpg'],
        ]);

        $this->assertSame(0, Artisan::call('stores:qamar'));
        $output = Artisan::output();

        $this->assertStringContainsString('Sitemap: 3 books; 3 new or changed; read 3 (3 with book details, 0 re-read for price and stock).', $output);
        $this->assertStringContainsString('Filled 1 covers and 1 page counts.', $output);

        // The bare edition: cover kept as WebP on our own disk, pages filled,
        // and its sync hash cleared so the bot gets it again.
        $edition = Edition::where('isbn', '9789943567993')->sole();
        $this->assertSame('covers/qamar/9789943567993.webp', $edition->cover_path);
        $this->assertSame(544, $edition->pages);
        $this->assertNull($edition->sync_hash);
        $this->assertStringStartsWith('RIFF', Storage::disk('public')->get('covers/qamar/9789943567993.webp'));

        $listing = StoreListing::where('isbn', '9789943567993')->sole();
        $this->assertSame(['cover', 'pages'], $listing->filled);
        $this->assertSame([
            'publisher' => ['Imom Buxoriy xalqaro markazi', 'Bio-factor press'],
            'author' => [null, 'Shayx Abulaziz Mansur'],
        ], $listing->differences);

        // An edition with its own cover and pages keeps them; two pages apart is no difference.
        $kunlar = Edition::where('isbn', '9789943000001')->sole();
        $this->assertSame('covers/ours.webp', $kunlar->cover_path);
        $this->assertSame(300, $kunlar->pages);
        $this->assertNull(StoreListing::where('isbn', '9789943000001')->sole()->differences);
        $this->assertFalse(collect($this->requested)->contains('https://qamar.uz/media/kunlar.jpg'));

        // A book the catalogue lacks is listed, not created.
        $this->assertNull(StoreListing::where('isbn', '9789943999999')->sole()->edition_id);
        $this->assertSame(2, Edition::count());

        $logs = Storage::disk('local')->files('store-feeds');
        $this->assertCount(1, $logs);
        $this->assertStringContainsString(",9789943567993,cover,\"covers/qamar/9789943567993.webp\"\n", Storage::disk('local')->get($logs[0]));
    }

    public function test_prices_link_first_and_covers_past_the_nights_cap_wait_for_the_next(): void
    {
        foreach (['9789943000011', '9789943000012', '9789943000013'] as $i => $isbn) {
            $this->catalogueBook(book: ['uzbek_title' => "Kitob {$i}"], edition: ['isbn' => $isbn, 'cover_path' => null], authors: []);
        }

        $this->shop([
            'https://qamar.uz/kitob/a' => ['name' => 'Kitob 0', 'isbn' => '9789943000011', 'image' => 'https://qamar.uz/media/a.jpg'],
            'https://qamar.uz/kitob/b' => ['name' => 'Kitob 1', 'isbn' => '9789943000012', 'image' => 'https://qamar.uz/media/b.jpg'],
            'https://qamar.uz/kitob/c' => ['name' => 'Kitob 2', 'isbn' => '9789943000013', 'image' => 'https://qamar.uz/media/c.jpg'],
        ]);

        $this->assertSame(0, Artisan::call('stores:qamar', ['--covers' => 1]));

        // Every listing is on its book tonight, so every price shows.
        $this->assertSame(3, StoreListing::whereNotNull('edition_id')->count());
        $this->assertSame(1, Edition::whereNotNull('cover_path')->count());
        // The two covers past the cap come back the next night.
        $this->assertSame(2, StoreListing::whereNull('applied_at')->count());

        $this->assertSame(0, Artisan::call('stores:qamar', ['--no-read' => true, '--covers' => 5]));

        $this->assertSame(3, Edition::whereNotNull('cover_path')->count());
        $this->assertSame(0, StoreListing::whereNull('applied_at')->count());
    }

    public function test_one_listing_that_fails_is_skipped_not_the_end_of_the_run(): void
    {
        foreach (['9789943000021', '9789943000022'] as $i => $isbn) {
            $this->catalogueBook(book: ['uzbek_title' => "Kitob {$i}"], edition: ['isbn' => $isbn, 'cover_path' => null], authors: []);
        }
        $this->shop([
            'https://qamar.uz/kitob/a' => ['name' => 'Kitob 0', 'isbn' => '9789943000021', 'image' => 'https://qamar.uz/media/a.jpg'],
            'https://qamar.uz/kitob/b' => ['name' => 'Kitob 1', 'isbn' => '9789943000022', 'image' => 'https://qamar.uz/media/b.jpg'],
        ]);

        // The first listing's comparison throws, as a broken picture once did.
        $this->app->instance(StoreListingApplier::class, new class extends StoreListingApplier
        {
            public function apply(StoreListing $listing, bool $dryRun = false, bool $withCover = true): array
            {
                if ($listing->isbn === '9789943000021') {
                    throw new \RuntimeException('broken picture');
                }

                return parent::apply($listing, $dryRun, $withCover);
            }
        });

        $this->assertSame(0, Artisan::call('stores:qamar'));

        $this->assertMatchesRegularExpression('/errors\s*\|\s*1/', Artisan::output());
        // Both are linked by ISBN before any comparison, so both prices show.
        $this->assertSame(2, StoreListing::whereNotNull('edition_id')->count());
        // The second was still compared and got its cover.
        $this->assertNotNull(Edition::where('isbn', '9789943000022')->value('cover_path'));
    }

    public function test_a_picture_too_large_to_be_a_cover_is_not_decoded(): void
    {
        $this->catalogueBook(book: ['uzbek_title' => 'Plakat'], edition: ['isbn' => '9789943000031', 'cover_path' => null], authors: []);
        $this->shop(['https://qamar.uz/kitob/p' => ['name' => 'Plakat', 'isbn' => '9789943000031', 'image' => 'https://qamar.uz/media/huge.png']]);
        $this->huge = true;

        $this->assertSame(0, Artisan::call('stores:qamar'));

        $this->assertNull(Edition::where('isbn', '9789943000031')->value('cover_path'));
        $this->assertNotNull(StoreListing::where('isbn', '9789943000031')->value('edition_id'));
    }

    public function test_the_next_night_reads_only_pages_that_changed(): void
    {
        $this->shop([
            'https://qamar.uz/kitob/a' => ['name' => 'A', 'isbn' => '9789943000011'],
            'https://qamar.uz/kitob/b' => ['name' => 'B', 'isbn' => '9789943000012'],
        ], lastmod: '2026-11-01T00:00:00Z');

        Artisan::call('stores:qamar');
        $this->requested = [];

        $this->shop([
            'https://qamar.uz/kitob/a' => ['name' => 'A', 'isbn' => '9789943000011'],
            'https://qamar.uz/kitob/b' => ['name' => 'B (2-nashr)', 'isbn' => '9789943000012'],
        ], lastmod: ['https://qamar.uz/kitob/a' => '2026-11-01T00:00:00Z', 'https://qamar.uz/kitob/b' => now()->addDay()->toIso8601String()]);

        Artisan::call('stores:qamar');

        $this->assertStringContainsString('2 books; 1 new or changed; read 1', Artisan::output());
        $this->assertSame(['https://qamar.uz/sitemap.xml', 'https://qamar.uz/kitob/b'], $this->requested);
        $this->assertSame('B (2-nashr)', StoreListing::where('url', 'https://qamar.uz/kitob/b')->sole()->title);
    }

    public function test_an_error_ends_the_run_and_a_dry_run_changes_nothing(): void
    {
        $this->catalogueBook(edition: ['isbn' => '9789943000021', 'cover_path' => null, 'pages' => null]);
        $this->shop([
            'https://qamar.uz/kitob/a' => ['name' => 'A', 'isbn' => '9789943000021', 'numberOfPages' => 100, 'image' => 'https://qamar.uz/media/a.jpg'],
            'https://qamar.uz/kitob/b' => ['name' => 'B', 'isbn' => '9789943000022'],
        ]);

        $this->failWithStatus = 429;
        Artisan::call('stores:qamar');
        $this->assertStringContainsString('Stopped early: Qamar: HTTP 429 for https://qamar.uz/kitob/a', Artisan::output());
        $this->assertSame(['https://qamar.uz/sitemap.xml', 'https://qamar.uz/kitob/a'], $this->requested);
        $this->assertSame(0, StoreListing::count());

        $this->failWithStatus = null;
        Artisan::call('stores:qamar', ['--dry-run' => true]);
        $this->assertStringContainsString('Would fill 1 covers and 1 page counts.', Artisan::output());

        $edition = Edition::where('isbn', '9789943000021')->sole();
        $this->assertNull($edition->cover_path);
        $this->assertNull($edition->pages);
        $this->assertSame([], Storage::disk('local')->files('store-feeds'));
    }

    public function test_an_admin_sees_the_listings_by_what_they_need(): void
    {
        $this->catalogueBook(edition: ['isbn' => '9789943000031', 'cover_path' => null]);
        $this->shop([
            'https://qamar.uz/kitob/bor' => ['name' => 'Bor kitob', 'isbn' => '9789943000031', 'image' => 'https://qamar.uz/media/bor.jpg'],
            'https://qamar.uz/kitob/yoq' => ['name' => "Yo'q kitob", 'isbn' => '9789943000032'],
        ]);
        Artisan::call('stores:qamar');

        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => UserRole::Admin]));

        $new = StoreListing::where('isbn', '9789943000032')->sole();
        $filled = StoreListing::where('isbn', '9789943000031')->sole();

        Livewire::test(ListStoreListings::class)
            ->assertCanSeeTableRecords([$new])
            ->assertCanNotSeeTableRecords([$filled])
            ->set('activeTab', 'filled')
            ->assertCanSeeTableRecords([$filled])
            ->assertCanNotSeeTableRecords([$new]);
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, array<string, mixed>>  $books  url => JSON-LD Book fields
     * @param  string|array<string, string>  $lastmod
     */
    private function shop(array $books, string|array $lastmod = '2026-11-09T00:00:00Z'): void
    {
        $this->pages = array_map(fn (array $book) => $this->bookPage($book), $books);
        $this->sitemap = $this->sitemapXml(collect($books)
            ->mapWithKeys(fn ($book, $url) => [$url => is_array($lastmod) ? $lastmod[$url] : $lastmod])
            ->all());
    }

    /** @param  array<string, ?string>  $urls  url => lastmod */
    private function sitemapXml(array $urls): string
    {
        $entries = collect($urls)
            ->map(fn (?string $lastmod, string $url) => "<url>\n<loc>{$url}</loc>\n".($lastmod ? "<lastmod>{$lastmod}</lastmod>\n" : '').'</url>')
            ->implode("\n");

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n{$entries}\n</urlset>";
    }

    /** @param  array<string, mixed>  $book */
    private function bookPage(array $book): string
    {
        $graph = json_encode(['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'Organization', '@id' => 'https://qamar.uz/#organization', 'name' => 'Qamar'],
            ['@type' => ['Product', 'Book'], ...$book],
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "<html><head><script type=\"application/ld+json\">{$graph}</script></head><body><p>Shop text.</p></body></html>";
    }

    /**
     * A PNG whose header says 5000 × 5000: all getimagesize reads. Decoding
     * a real one needs about 100 MB, as a poster-sized "cover" on the shop
     * would, which is the point of the check.
     */
    private function hugePngHeader(): string
    {
        $ihdr = 'IHDR'.pack('NNCCCCC', 5000, 5000, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr)).str_repeat("\0", 64);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(4, 6);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
