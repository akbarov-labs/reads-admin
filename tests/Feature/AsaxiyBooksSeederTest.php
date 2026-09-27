<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\Edition;
use App\Models\Publisher;
use App\Models\Translator;
use Database\Seeders\AsaxiyBooksSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

class AsaxiyBooksSeederTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    private const HEADERS = ['title', 'author', 'translator', 'isbn', 'article', 'cover', 'cover_material', 'paper_format', 'pages', 'page_surface', 'language', 'script', 'publisher', 'country', 'published_year', 'description', 'volume_part', 'url'];

    private string $workbook;

    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->workbook = tempnam(sys_get_temp_dir(), 'asaxiy').'.xlsx';
        $this->cache = sys_get_temp_dir().'/asaxiy-cache-'.uniqid();
        putenv("ASAXIY_BOOKS_FILE={$this->workbook}");
        putenv("ASAXIY_CACHE_DIR={$this->cache}");
    }

    protected function tearDown(): void
    {
        putenv('ASAXIY_BOOKS_FILE');
        putenv('ASAXIY_CACHE_DIR');
        @unlink($this->workbook);
        File::deleteDirectory($this->cache);

        parent::tearDown();
    }

    public function test_spellings_of_one_publisher_become_one_row_and_reuse_an_existing_one(): void
    {
        Publisher::create(['name' => 'Hilol nashr', 'slug' => 'hilol-nashr']);

        $this->writeWorkbook([
            $this->row(['title' => 'Kitob bir', 'isbn' => '978-9943-23-198-6', 'publisher' => 'Ҳилол-нашр']),
            $this->row(['title' => 'Kitob ikki', 'isbn' => '9789943231672', 'publisher' => 'HILOL-Nashr']),
            $this->row(['title' => 'Kitob uch', 'isbn' => '9789910865817', 'publisher' => 'Hilol media']),
        ]);

        $this->seed(AsaxiyBooksSeeder::class);

        $hilol = Publisher::where('slug', 'hilol-nashr')->sole();
        $this->assertSame(2, $hilol->editions()->count());
        $this->assertSame(2, Publisher::count(), 'Hilol media is a different publisher');
    }

    public function test_an_uzbek_cyrillic_row_becomes_a_latin_book_and_edition(): void
    {
        Storage::disk('public')->put('downloads/9789943231986.webp', 'webp');

        $this->writeWorkbook([
            $this->row([
                'title' => 'Анбиё Йилдирим: Ҳазиллашиш одоби (кирилл)',
                'translator' => 'Достон Мустафоев, Ойбек Сотиболдиев',
                'isbn' => '978-9943-23-198-6',
                'article' => 'T35050',
                'cover' => 'Юмшоқ',
                'cover_material' => 'Qog‘oz',
                'paper_format' => 'А5',
                'pages' => '744',
                'page_surface' => 'yaltiroq',
                'script' => 'Kirillcha',
                'publisher' => 'Янги Китоб',
                'country' => 'Turkiya',
                'description' => "Mahsulot ta'rifi Китоб ҳақида",
            ]),
        ]);

        $this->seed(AsaxiyBooksSeeder::class);

        $book = Book::with(['authors', 'editions.translators', 'editions.publisher'])->sole();
        $edition = $book->editions->sole();

        $this->assertSame('Hazillashish odobi', $book->uzbek_title);
        $this->assertSame('Anbiyo Yildirim', $book->author_names);
        $this->assertSame('Yangi Kitob', $edition->publisher->name);
        // Both translators credited, in order, as account-less profiles.
        $this->assertSame(['Doston Mustafoyev', 'Oybek Sotiboldiyev'], $edition->translators->pluck('name')->all());
        $this->assertNull($edition->translators->first()->user_id);
        $this->assertSame('9789943231986', $edition->isbn);
        $this->assertSame('downloads/9789943231986.webp', $edition->cover_path);
        $this->assertSame("O'zbek tili", $edition->language);
        $this->assertSame('Kirill', $edition->script);
        $this->assertSame('Yumshoq', $edition->cover_type);
        $this->assertSame("Qog'oz", $edition->cover_material, 'apostrophes normalised like every other cell');
        $this->assertSame('A5', $edition->paper_format);
        $this->assertSame('Yaltiroq', $edition->page_surface);
        $this->assertSame('Turkiya', $edition->print_country);
        $this->assertSame(744, $edition->pages);
        $this->assertSame('Kitob haqida', $edition->description);
        $this->assertSame('T35050', $edition->source_article);
        // A translation: the listing does not say from which language.
        $this->assertNull($book->source_language);
    }

    public function test_two_printings_of_one_book_are_one_book_with_two_editions(): void
    {
        $this->writeWorkbook([
            $this->row(['title' => 'Piter Hopkirk: Katta o‘yin', 'author' => 'Piter Hopkirk', 'isbn' => '9789943231986', 'publisher' => 'Asaxiy Books', 'translator' => 'Shodiyor Nishonboyev']),
            $this->row(['title' => "Katta o'yin", 'author' => 'Piter Hopkirk', 'isbn' => '9789943231672', 'publisher' => 'Yangi asr avlodi', 'translator' => 'Boshqa Tarjimon']),
            $this->row(['title' => "Katta o'yin", 'author' => 'Boshqa Muallif', 'isbn' => '9789910865817']),
        ]);

        $this->seed(AsaxiyBooksSeeder::class);

        $hopkirk = Author::where('name', 'Piter Hopkirk')->sole()->books()->sole();
        $this->assertSame(2, $hopkirk->editions()->count());
        $this->assertSame(2, Book::count(), 'same title by someone else is another book');
    }

    public function test_an_edition_of_a_book_entered_by_hand_joins_that_book(): void
    {
        $manual = $this->catalogueBook(['uzbek_title' => 'Chol va dengiz'], authors: [$this->author('Ernest Xeminguey')]);

        $this->writeWorkbook([
            $this->row(['title' => 'Ernest Xeminguey: Chol va dengiz', 'author' => 'Ernest Xeminguey', 'isbn' => '9789943231986']),
        ]);

        $this->seed(AsaxiyBooksSeeder::class);

        $this->assertSame(1, Book::count());
        $this->assertSame(2, $manual->editions()->count());
    }

    public function test_the_original_spelling_of_an_author_is_kept(): void
    {
        $this->writeWorkbook([
            $this->row(['title' => 'Bygone days', 'author' => 'Абдулла Қодирий', 'language' => 'Inglizcha', 'isbn' => '9789910773266']),
            $this->row(["title" => "O'tkan kunlar", 'author' => 'Abdulla Qodiriy', 'isbn' => '9789943231986']),
        ]);

        $this->seed(AsaxiyBooksSeeder::class);

        $author = Author::sole();
        $this->assertSame('Abdulla Qodiriy', $author->name, 'Latin spelling preferred for the catalogue');
        $this->assertSame('Абдулла Қодирий', $author->original_name);
        $this->assertSame(2, $author->books()->count());
    }

    public function test_numbered_volumes_become_a_series_but_a_lone_number_does_not(): void
    {
        $this->writeWorkbook([
            $this->row(['title' => 'Tohir Malik: Shaytanat 1-kitob', 'author' => 'Tohir Malik', 'isbn' => '9789943231986']),
            $this->row(['title' => 'Tohir Malik: Shaytanat 2-kitob', 'author' => 'Tohir Malik', 'isbn' => '9789943231672']),
            $this->row(['title' => 'Yolg‘iz kitob 3-qism', 'author' => 'Tohir Malik', 'isbn' => '9789910865817']),
        ]);

        $this->seed(AsaxiyBooksSeeder::class);

        $second = Book::where('uzbek_title', 'Shaytanat 2-kitob')->sole();
        $this->assertSame('Shaytanat', $second->series->name);
        $this->assertSame(2.0, $second->series_position);
        $this->assertNull(Book::where('uzbek_title', 'like', 'Yolg%')->sole()->series_id);
    }

    public function test_scraped_shelves_gallery_and_recommendations_are_attached(): void
    {
        $this->cachePage('T1', ['breadcrumb' => 'Jahon adabiyoti', 'gallery' => ['a', 'b'], 'related' => ['https://asaxiy.uz/product/2']]);
        $this->cacheCategory('bestseller', ['https://asaxiy.uz/product/1']);
        Storage::disk('public')->put('downloads/9789943231986-2.webp', 'webp');
        Storage::disk('public')->put('downloads/9789943231986-3.webp', 'webp');

        $this->writeWorkbook([
            $this->row(['title' => 'Birinchi', 'isbn' => '9789943231986', 'article' => 'T1', 'url' => 'https://asaxiy.uz/product/1']),
            $this->row(['title' => 'Ikkinchi', 'isbn' => '9789943231672', 'article' => 'T2', 'url' => 'https://asaxiy.uz/product/2']),
        ]);

        $this->seed(AsaxiyBooksSeeder::class);

        $book = Book::where('uzbek_title', 'Birinchi')->sole();

        $this->assertEqualsCanonicalizing(['Jahon adabiyoti', 'TOP-100 bestseller'], $book->categories->pluck('name')->all());
        $this->assertSame('Jahon adabiyoti', $book->categories->firstWhere('pivot.is_primary', true)->name);
        $this->assertSame(['downloads/9789943231986-2.webp', 'downloads/9789943231986-3.webp'], $book->editions->first()->images->pluck('path')->all());
        $this->assertSame(['Ikkinchi'], $book->relatedBooks->pluck('uzbek_title')->all());
    }

    public function test_gift_boxes_bundles_and_puzzles_are_not_imported_as_books(): void
    {
        $this->cachePage('T1', ['breadcrumb' => 'Kitobli sovg‘a to‘plamlari']);
        $this->cachePage('T2', ['breadcrumb' => 'Bestseller to‘plamlar']);
        $this->cachePage('T3', ['breadcrumb' => 'Pazllar']);
        // On a bundle shelf, but a publisher's real book with an ISBN.
        $this->cachePage('T4', ['breadcrumb' => 'Bestseller to‘plamlar']);
        $this->cachePage('T5', ['breadcrumb' => 'Jahon adabiyoti']);

        $this->writeWorkbook([
            $this->row(['title' => "Sovg'a uchun to'plam. Ajoyib narx va yetkazib berish bepul. (299.000, Sovgʻabop quti)", 'article' => 'T1', 'publisher' => 'Asaxiy Books']),
            $this->row(['title' => 'Beshtasi birda va super chegirma narxda!', 'article' => 'T2', 'publisher' => 'Asaxiy Books']),
            $this->row(['title' => 'Step Puzzle Буратино 260 pazllari', 'article' => 'T3', 'isbn' => '4602827740716']),
            $this->row(['title' => 'Podshohning qizi (3D panorama)', 'article' => 'T4', 'isbn' => '9789943800953']),
            $this->row(['title' => 'Собрание сочинений в 8 томах (комплект)', 'article' => 'T5', 'language' => 'Ruscha']),
            // No scraped page: the gift box is still known by its title.
            $this->row(['title' => "Sovg'a uchun to'plam. Yetkazib berish bepul.", 'article' => 'T6']),
        ]);

        $this->seed(AsaxiyBooksSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['Podshohning qizi (3D panorama)', 'Собрание сочинений в 8 томах (комплект)'],
            Book::pluck('uzbek_title')->all(),
        );
    }

    public function test_bundles_imported_by_an_earlier_run_are_removed_unless_reviewed(): void
    {
        $this->writeWorkbook([
            $this->row(['title' => 'Erkaklar uchun jiddiy to\'plam', 'article' => 'T1']),
            $this->row(['title' => 'Kattalar uchun hayitlik to\'plami', 'article' => 'T2']),
        ]);
        $this->seed(AsaxiyBooksSeeder::class);
        $this->assertSame(2, Book::count());

        $reviewed = Edition::where('source_article', 'T2')->sole();
        $user = \App\Models\User::create(['name' => 'Nodira', 'email' => 'nodira@example.test', 'password' => 'password', 'role' => \App\Enums\UserRole::Taqrizchi]);
        $taqrizchi = \App\Models\Taqrizchi::create(['user_id' => $user->id, 'slug' => 'nodira', 'name' => 'Nodira']);
        \App\Models\Taqriz::create(['edition_id' => $reviewed->id, 'taqrizchi_id' => $taqrizchi->id, 'body' => 'Matn.']);

        // Now the scrape says what shelf they are on.
        $this->cachePage('T1', ['breadcrumb' => 'Bestseller to‘plamlar']);
        $this->cachePage('T2', ['breadcrumb' => 'Bestseller to‘plamlar']);
        $this->seed(AsaxiyBooksSeeder::class);

        $this->assertSame(['Kattalar uchun hayitlik to\'plami'], Book::pluck('uzbek_title')->all());
    }

    public function test_running_it_again_duplicates_nothing_and_attaches_new_covers(): void
    {
        $this->writeWorkbook([
            $this->row(['title' => 'Kitob', 'author' => 'Muallif Ismi', 'isbn' => '9789943231986', 'article' => 'T1', 'publisher' => 'Nihol', 'translator' => 'Tarjimon Bir']),
            // The same ISBN listed twice.
            $this->row(['title' => 'Kitob (qattiq muqova)', 'author' => 'Muallif Ismi', 'isbn' => '978-9943-23-198-6', 'article' => 'T2', 'pages' => '320', 'publisher' => 'Nihol nashr', 'translator' => 'Tarjimon Ikki']),
            // No ISBN: kept, keyed by article number.
            $this->row(['title' => 'Boshqa kitob', 'article' => 'T3']),
        ]);

        $this->seed(AsaxiyBooksSeeder::class);

        $this->assertSame(2, Edition::count());
        $edition = Edition::where('isbn', '9789943231986')->sole();
        $this->assertSame(320, $edition->pages, 'the duplicate filled the gap');
        $this->assertSame(2, $edition->translators()->count(), 'both listings\' translators credited');
        $this->assertNull($edition->cover_path);

        $edition->update(['pages' => 999]);
        Storage::disk('public')->put('downloads/9789943231986.webp', 'webp');

        $this->seed(AsaxiyBooksSeeder::class);

        $this->assertSame(2, Book::count());
        $this->assertSame(2, Edition::count());
        $this->assertSame(1, Publisher::count());
        $this->assertSame(2, Translator::count());
        $this->assertSame(999, $edition->fresh()->pages, 'an admin edit survives');
        $this->assertSame('downloads/9789943231986.webp', $edition->fresh()->cover_path);
    }

    /** @param  array<string, ?string>  $values */
    private function row(array $values): array
    {
        static $article = 100;

        $defaults = [
            'title' => 'Kitob', 'author' => null, 'translator' => null, 'isbn' => null,
            'article' => 'T'.$article++, 'cover' => null, 'cover_material' => null, 'paper_format' => null,
            'pages' => null, 'page_surface' => null, 'language' => "O'zbekcha", 'script' => null,
            'publisher' => null, 'country' => null, 'published_year' => '2023', 'description' => null,
            'volume_part' => null, 'url' => null,
        ];

        $row = array_merge($defaults, $values);
        $row['url'] ??= 'https://asaxiy.uz/product/'.$row['article'];

        return array_map(fn (string $header) => $row[$header], self::HEADERS);
    }

    /** @param  list<list<?string>>  $rows */
    private function writeWorkbook(array $rows): void
    {
        $writer = new Writer;
        $writer->openToFile($this->workbook);
        $writer->addRow(Row::fromValues(self::HEADERS));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();
    }

    /** @param  array<string, mixed>  $page */
    private function cachePage(string $article, array $page): void
    {
        File::ensureDirectoryExists("{$this->cache}/pages");
        File::put("{$this->cache}/pages/{$article}.json", json_encode($page));
    }

    /** @param  list<string>  $products */
    private function cacheCategory(string $path, array $products): void
    {
        File::ensureDirectoryExists("{$this->cache}/categories");
        File::put("{$this->cache}/categories/{$path}.json", json_encode(['path' => $path, 'products' => $products]));
    }
}
