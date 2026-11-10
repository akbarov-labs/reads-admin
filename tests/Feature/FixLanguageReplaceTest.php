<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\AuthorAlias;
use App\Models\Book;
use App\Models\Publisher;
use App\Models\PublisherAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * catalogue:fix-language-replace proposes every repair in a CSV, changes
 * nothing until it is applied, and keeps old links working.
 */
class FixLanguageReplaceTest extends TestCase
{
    use RefreshDatabase;

    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->csv = tempnam(sys_get_temp_dir(), 'fixes');
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);

        parent::tearDown();
    }

    /** @return list<array<string, string>> */
    private function rows(): array
    {
        $lines = array_map(fn (string $line) => str_getcsv($line, escape: ''), file($this->csv, FILE_IGNORE_NEW_LINES));
        $header = array_shift($lines);

        return array_map(fn (array $line) => array_combine($header, $line), $lines);
    }

    private function writeRows(array $rows): void
    {
        $handle = fopen($this->csv, 'w');
        fputcsv($handle, array_keys($rows[0]), escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }
        fclose($handle);
    }

    public function test_it_proposes_repairs_and_changes_nothing(): void
    {
        $book = Book::create(['uzbek_title' => "O'zbekchauklar hukmdori", 'original_title' => 'The Lord of the Rings', 'description' => 'Inglizchadan tarjima qilingan.']);
        Book::create(['uzbek_title' => "Inglizcha-o'zbekcha lug'at", 'original_title' => 'x']);

        $this->artisan('catalogue:fix-language-replace', ['--csv' => $this->csv])->assertSuccessful();

        $rows = $this->rows();
        $this->assertCount(2, $rows);
        $this->assertSame(['books', (string) $book->id, 'uzbek_title', "O'zbekchauklar hukmdori", 'Uzuklar hukmdori', 'yes', 'yes'], array_values($rows[0]));
        $this->assertSame(['description', 'check', 'no'], [$rows[1]['column'], $rows[1]['sure'], $rows[1]['apply']]);

        $this->assertSame("O'zbekchauklar hukmdori", $book->refresh()->uzbek_title);
    }

    public function test_applying_renames_keeps_old_links_and_merges_into_names_already_there(): void
    {
        $garbled = Author::create(['name' => "DilfO'zbekchaa XudaybergInglizchaova", 'slug' => 'dilfozbekchaa-xudayberginglizchaova']);
        $duplicate = Author::create(['name' => 'StivInglizcha King', 'slug' => 'stivinglizcha-king']);
        $king = Author::create(['name' => 'Stiven King', 'slug' => 'stiven-king']);
        $huzur = Publisher::create(['name' => "HO'zbekchaur kitoblari", 'slug' => 'hozbekchaur-kitoblari']);
        $book = Book::create(['uzbek_title' => 'Baqaloq sargO\'zbekchaashtlari', 'original_title' => 'x']);
        $book->authors()->attach($duplicate->id, ['position' => 0]);

        $this->artisan('catalogue:fix-language-replace', ['--csv' => $this->csv])->assertSuccessful();
        $this->artisan('catalogue:fix-language-replace', ['--apply' => $this->csv])
            ->expectsOutputToContain('1 value(s) fixed, 2 author(s)/publisher(s) renamed, 1 merged into one already there')
            ->assertSuccessful();

        $garbled->refresh();
        $this->assertSame('Dilfuza Xudaybergenova', $garbled->name);
        $this->assertSame('dilfuza-xudaybergenova', $garbled->slug);
        $this->assertTrue(AuthorAlias::where('author_id', $garbled->id)->where('slug', 'dilfozbekchaa-xudayberginglizchaova')->exists());
        $this->assertTrue($garbled->is((new Author)->resolveRouteBinding('dilfozbekchaa-xudayberginglizchaova')));

        $this->assertNull(Author::find($duplicate->id));
        $this->assertSame([$king->id], $book->refresh()->authors()->pluck('authors.id')->all());

        $huzur->refresh();
        $this->assertSame('Huzur kitoblari', $huzur->name);
        $this->assertSame('huzur-kitoblari', $huzur->slug);
        $this->assertTrue(PublisherAlias::where('publisher_id', $huzur->id)->where('slug', 'hozbekchaur-kitoblari')->exists());

        $this->assertSame('Baqaloq sarguzashtlari', $book->uzbek_title);
        $this->assertStringContainsString('sarguzashtlari', (string) $book->search_key);
    }

    public function test_only_rows_marked_to_apply_and_unchanged_since_are_applied(): void
    {
        $first = Book::create(['uzbek_title' => "O'zbekchauklar hukmdori", 'original_title' => 'x']);
        $second = Book::create(['uzbek_title' => 'YovO\'zbekchalik', 'original_title' => 'x']);
        $third = Book::create(['uzbek_title' => 'Ruschas tili', 'original_title' => 'x']);

        $this->artisan('catalogue:fix-language-replace', ['--csv' => $this->csv])->assertSuccessful();

        $rows = $this->rows();
        $rows[1]['apply'] = 'no';
        $this->writeRows($rows);
        // Someone fixed this one by hand in the meantime.
        $third->update(['uzbek_title' => 'Rus tili (A dan Ya gacha)']);

        $this->artisan('catalogue:fix-language-replace', ['--apply' => $this->csv])
            ->expectsOutputToContain('1 value(s) fixed, 0 author(s)/publisher(s) renamed, 0 merged into one already there; 1 changed since the CSV was written, 1 not marked to apply.')
            ->assertSuccessful();

        $this->assertSame('Uzuklar hukmdori', $first->refresh()->uzbek_title);
        $this->assertSame('YovO\'zbekchalik', $second->refresh()->uzbek_title);
        $this->assertSame('Rus tili (A dan Ya gacha)', $third->refresh()->uzbek_title);
    }
}
