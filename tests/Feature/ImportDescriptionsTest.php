<?php

namespace Tests\Feature;

use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/** Reviewed descriptions from a file, for books that have none anywhere. */
class ImportDescriptionsTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::fake();
    }

    public function test_it_fills_only_books_with_no_description_and_their_bare_editions(): void
    {
        $bare = $this->catalogueBook(book: ['uzbek_title' => 'Alkimyogar'], edition: ['isbn' => '9789943000101', 'description' => null, 'sync_hash' => 'bot-has-it']);
        $bare->editions()->create(['isbn' => '9789943000102']);
        $ownText = $this->catalogueBook(book: ['uzbek_title' => 'Boburnoma', 'description' => 'Bizning matn.'], edition: ['isbn' => '9789943000103']);
        $editionText = $this->catalogueBook(book: ['uzbek_title' => 'Kecha va kunduz'], edition: ['isbn' => '9789943000104', 'description' => "Do'kondan olingan matn."]);

        $file = $this->csv([
            [$bare->id, "Paulo Koeloning “Alkimyogar” romani (1988)…\n\n\n\nIkkinchi xatboshi, o‘z yo‘li haqida."],
            [$ownText->id, 'Yangi matn'],
            [$editionText->id, 'Yangi matn'],
            [999999, 'Kitob yoq'],
            [$bare->id + 1000, ''],
        ]);

        $this->assertSame(0, Artisan::call('books:import-descriptions', ['file' => $file, '--dry-run' => true]));
        $this->assertStringContainsString('Would describe 1 books.', Artisan::output());
        $this->assertNull($bare->fresh()->description);

        $this->assertSame(0, Artisan::call('books:import-descriptions', ['file' => $file]));
        $this->assertStringContainsString('Described 1 books.', Artisan::output());

        // Paragraphs kept, one blank line between them, one apostrophe style.
        $expected = "Paulo Koeloning “Alkimyogar” romani (1988)…\n\nIkkinchi xatboshi, o'z yo'li haqida.";
        $this->assertSame($expected, $bare->fresh()->description);

        foreach ($bare->editions()->get() as $edition) {
            $this->assertSame($expected, $edition->description);
            $this->assertNull($edition->sync_hash);
        }

        // A book with any description of its own or on an edition is left alone.
        $this->assertSame('Bizning matn.', $ownText->fresh()->description);
        $this->assertNull($editionText->fresh()->description);
        $this->assertSame("Do'kondan olingan matn.", $editionText->editions()->first()->description);

        $logs = Storage::disk('local')->files('description-imports');
        $this->assertCount(1, $logs);
        $this->assertStringStartsWith("book_id,edition_ids\n{$bare->id},\"", Storage::disk('local')->get($logs[0]));

        // Again: nothing left to fill.
        Artisan::call('books:import-descriptions', ['file' => $file]);
        $this->assertStringContainsString('Described 0 books.', Artisan::output());
        $this->assertSame(1, Book::whereNotNull('description')->where('id', $bare->id)->count());
    }

    public function test_over_copies_gives_a_book_its_own_text_and_leaves_the_shop_text_on_its_editions(): void
    {
        $copied = $this->catalogueBook(book: ['uzbek_title' => 'Sinbad'], edition: ['isbn' => '9780194400923', 'description' => 'Seriya haqida umumiy matn.']);
        $copied->editions()->first()->forceFill(['sync_hash' => 'bot-has-it'])->save();
        $copied->editions()->create(['isbn' => '9780194400930']);
        $ownText = $this->catalogueBook(book: ['uzbek_title' => 'Boburnoma', 'description' => 'Bizning matn.'], edition: ['isbn' => '9789943000103']);

        $file = $this->csv([
            [$copied->id, 'Sinbadning dengiz safarlari, 3-daraja uchun.'],
            [$ownText->id, 'Yangi matn'],
        ]);

        $this->assertSame(0, Artisan::call('books:import-descriptions', ['file' => $file, '--over-copies' => true]));
        $this->assertStringContainsString('Described 1 books.', Artisan::output());

        $this->assertSame('Sinbadning dengiz safarlari, 3-daraja uchun.', $copied->fresh()->description);
        [$shopEdition, $bareEdition] = $copied->editions()->orderBy('id')->get()->all();
        // The edition with the shop's text keeps it, and the bot isn't told to re-pull it.
        $this->assertSame('Seriya haqida umumiy matn.', $shopEdition->description);
        $this->assertSame('bot-has-it', $shopEdition->sync_hash);
        // A bare edition gets ours, as without the option.
        $this->assertSame('Sinbadning dengiz safarlari, 3-daraja uchun.', $bareEdition->description);

        // A book with its own description is still left alone.
        $this->assertSame('Bizning matn.', $ownText->fresh()->description);
    }

    public function test_a_file_without_the_columns_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'desc');
        file_put_contents($path, "id,text\n1,x\n");

        $this->assertSame(1, Artisan::call('books:import-descriptions', ['file' => $path]));
        $this->assertStringContainsString('needs the columns book_id and description', Artisan::output());
    }

    /** @param  list<array{0: int, 1: string}>  $rows */
    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'desc');
        $handle = fopen($path, 'w');
        fputcsv($handle, ['book_id', 'description']);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $path;
    }
}
