<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/** 2026_10_19_000002 spells "mo''jiza" with one apostrophe, as the catalogue does. */
class DoubledApostrophesMigrationTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    public function test_doubled_apostrophes_inside_words_become_one_and_nothing_else_changes(): void
    {
        $book = $this->catalogueBook(['uzbek_title' => 'Placeholder'], ['isbn' => '9789943505148']);
        $quoted = $this->catalogueBook(['uzbek_title' => 'Quoted'], ['isbn' => '9789943000001']);
        $author = Author::create(['name' => 'Placeholder', 'slug' => 'nomon-abdulmajid']);

        // Straight to the columns, as they were stored before.
        DB::table('books')->where('id', $book->id)->update(['uzbek_title' => "Ayol – mo''tabar zot", 'updated_at' => '2026-03-01 10:00:00']);
        DB::table('books')->where('id', $quoted->id)->update(['uzbek_title' => "''Kitob'' haqida"]);
        DB::table('authors')->where('id', $author->id)->update(['name' => "No''mon Abdulmajid"]);

        $this->migrate();

        $this->assertSame("Ayol – mo'tabar zot", Book::find($book->id)->uzbek_title);
        $this->assertSame("''Kitob'' haqida", Book::find($quoted->id)->uzbek_title);
        $this->assertSame("No'mon Abdulmajid", Author::find($author->id)->name);
        $this->assertSame('2026-03-01 10:00:00', (string) DB::table('books')->where('id', $book->id)->value('updated_at'), 'the bot is not told about it');
    }

    public function test_an_author_already_spelt_right_is_left_for_a_merge_not_renamed_into_a_clash(): void
    {
        Author::create(['name' => "No'mon Abdulmajid", 'slug' => 'nomon-abdulmajid']);
        $twin = Author::create(['name' => 'Placeholder', 'slug' => 'nomon-abdulmajid-2']);
        DB::table('authors')->where('id', $twin->id)->update(['name' => "No''mon Abdulmajid"]);

        $this->migrate();

        $this->assertSame("No''mon Abdulmajid", Author::find($twin->id)->name);
    }

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_19_000002_collapse_doubled_apostrophes.php'))->up();
    }
}
