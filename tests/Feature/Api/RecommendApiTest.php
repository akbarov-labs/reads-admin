<?php

namespace Tests\Feature\Api;

use App\Enums\ModerationStatus;
use App\Models\Book;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * /api/recommend answers the site's "recommend me a book" quiz: books for
 * a genre, a length and popular-or-new, best first, a few at a time.
 */
class RecommendApiTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    private function book(string $title, array $edition = []): Book
    {
        // The reader_* counts are the bot sync's to write, not fillable.
        $counts = array_filter($edition, fn (string $column) => str_starts_with($column, 'reader_'), ARRAY_FILTER_USE_KEY);
        $book = $this->catalogueBook(['uzbek_title' => $title], ['cover_path' => "covers/{$title}.jpg", ...array_diff_key($edition, $counts)]);
        $book->editions()->first()->forceFill($counts)->save();

        return $book;
    }

    private function views(Book $book, int $views, int $daysAgo = 1): void
    {
        DB::table('book_views')->insert([
            'book_id' => $book->id,
            'day' => now('Asia/Tashkent')->subDays($daysAgo)->toDateString(),
            'locale' => 'uz',
            'views' => $views,
        ]);
    }

    /** @return list<string> */
    private function titles(string $query = ''): array
    {
        return collect($this->getJson("/api/recommend?{$query}")->assertOk()->json('data'))
            ->pluck('uzbekTitle')->all();
    }

    public function test_books_come_as_catalogue_cards(): void
    {
        $book = $this->book('Chol va dengiz');

        $this->getJson('/api/recommend')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $book->id)
            ->assertJsonPath('data.0.coverUrl', '/storage/covers/Chol va dengiz.jpg')
            ->assertJsonPath('data.0.author', 'Ernest Hemingway')
            ->assertJsonPath('meta.per_page', 8);
    }

    public function test_only_books_with_a_cover_are_suggested(): void
    {
        $this->book('Covered');
        $this->catalogueBook(['uzbek_title' => 'Bare']);
        $pending = $this->catalogueBook(['uzbek_title' => 'Pending cover']);
        $this->edition($pending, ['cover_path' => 'covers/p.jpg', 'status' => ModerationStatus::Pending]);

        $this->assertSame(['Covered'], $this->titles());
    }

    public function test_popular_ranks_views_bot_readers_and_ratings(): void
    {
        $viewed = $this->book('Viewed');
        $this->views($viewed, 25);
        $this->views($viewed, 1000, daysAgo: 200); // too long ago to count

        // Three bot readers: 30.
        $read = $this->book('Read', ['reader_read_count' => 2, 'reader_want_count' => 1]);

        // Two fives: +40. Two ones on another: −40.
        $loved = $this->book('Loved', ['reader_rating_count' => 2, 'reader_rating_total' => 10]);
        $disliked = $this->book('Disliked', ['reader_rating_count' => 2, 'reader_rating_total' => 2]);
        $this->views($disliked, 30);

        $this->book('Unknown');

        $this->assertSame(['Loved', 'Read', 'Viewed', 'Unknown', 'Disliked'], $this->titles());
    }

    public function test_new_puts_the_newest_edition_first(): void
    {
        $old = $this->book('Old', ['year' => 1990]);
        $this->views($old, 500);
        $this->book('New', ['year' => 2025]);

        $this->assertSame(['New', 'Old'], $this->titles('pick=new'));
        $this->assertSame(['Old', 'New'], $this->titles('pick=popular'));
    }

    public function test_a_genre_includes_its_children(): void
    {
        $parent = Category::create(['slug' => 'badiiy', 'name' => 'Badiiy adabiyot']);
        $child = Category::create(['slug' => 'jahon', 'name' => 'Jahon adabiyoti', 'parent_id' => $parent->id]);
        $this->book('Novel')->categories()->attach($child->id, ['is_primary' => true]);
        $this->book('Other');

        $this->assertSame(['Novel'], $this->titles('genre=jahon'));
        $this->assertSame(['Novel'], $this->titles('genre=badiiy'));
    }

    public function test_length_goes_by_page_count(): void
    {
        $this->book('Short', ['pages' => 120]);
        $this->book('Medium', ['pages' => 200]);
        $this->book('Long', ['pages' => 640]);
        $this->book('Unknown length');

        $this->assertSame(['Short'], $this->titles('length=short'));
        $this->assertSame(['Medium'], $this->titles('length=medium'));
        $this->assertSame(['Long'], $this->titles('length=long'));
    }

    public function test_the_next_page_shows_others(): void
    {
        foreach (range(1, 10) as $i) {
            $this->views($this->book("Book {$i}"), 100 - $i);
        }

        $this->getJson('/api/recommend')->assertJsonCount(8, 'data')->assertJsonPath('meta.last_page', 2);
        $this->assertSame(['Book 9', 'Book 10'], $this->titles('page=2'));
    }

    public function test_an_unknown_answer_is_refused(): void
    {
        $this->getJson('/api/recommend?length=huge')->assertUnprocessable();
        $this->getJson('/api/recommend?pick=random')->assertUnprocessable();
    }
}
