<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/** A copied text that turned out to describe another book is taken off. */
class ClearCopiedTextsTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::fake();
    }

    public function test_it_clears_the_shown_copied_text_from_every_edition_that_carries_it_and_logs_it(): void
    {
        $wrong = str_repeat('Dostoyevskiyning “Telba” romani haqidagi boshqa kitobning matni. ', 3);
        $other = str_repeat('Boshqa nashrning tavsifi, joyida qoladi. ', 3);
        $book = $this->catalogueBook(book: ['uzbek_title' => 'Обыкновенная история', 'description' => 'Bizning matn.'], edition: ['isbn' => '9785170000001', 'description' => $wrong]);
        $this->edition($book, ['isbn' => '9785170000002', 'description' => $wrong]);
        $this->edition($book, ['isbn' => '9785170000003', 'description' => $other]);
        $bare = $this->catalogueBook(edition: ['isbn' => '9785170000004', 'description' => null]);

        $file = tempnam(sys_get_temp_dir(), 'clear');
        file_put_contents($file, "book_id\n{$book->id}\n{$bare->id}\n999999\n");

        $this->assertSame(0, Artisan::call('books:clear-copied-texts', ['file' => $file, '--dry-run' => true]));
        $this->assertStringContainsString('Would clear the copied text on 1 books.', Artisan::output());
        $this->assertSame(2, $book->editions()->where('description', 'like', 'Dostoyevskiy%')->count());

        $this->assertSame(0, Artisan::call('books:clear-copied-texts', ['file' => $file]));
        $this->assertStringContainsString('Cleared the copied text on 1 books.', Artisan::output());

        $descriptions = $book->editions()->orderBy('id')->pluck('description')->all();
        $this->assertSame([null, null, $other], $descriptions);
        // The book's own text is untouched, and nothing is folded under it any more except the other edition's.
        $this->assertSame('Bizning matn.', $book->fresh()->description);
        $this->assertSame(trim($other), $book->fresh()->load('approvedEditions')->shop_about['text']);

        $logs = Storage::disk('local')->files('description-clears');
        $this->assertCount(1, $logs);
        $logged = json_decode(Storage::disk('local')->get($logs[0]), true);
        $this->assertCount(2, $logged);
        $this->assertSame($wrong, $logged[0]['description']);
    }
}
