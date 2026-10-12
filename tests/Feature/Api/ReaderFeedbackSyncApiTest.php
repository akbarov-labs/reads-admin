<?php

namespace Tests\Feature\Api;

use App\Enums\ModerationStatus;
use App\Enums\UserRole;
use App\Filament\Resources\ReaderReviewResource\Pages\ListReaderReviews;
use App\Models\Edition;
use App\Models\ReaderReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * Ratings and reviews from Kitoblarim bot readers (docs/DATA-MODEL.md,
 * "Ratings and reviews from the bot"): sent whole by the bot, anonymous
 * ratings on the edition, reviews held for a moderator.
 */
class ReaderFeedbackSyncApiTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    private const TOKEN = 'bot-sync-secret';

    private const ISBN = '9789943231986';

    private const OTHER_ISBN = '9789943231993';

    protected function setUp(): void
    {
        parent::setUp();

        config(['catalogue.sync_token' => self::TOKEN]);
    }

    // ------------------------------------------------------------------
    // Ratings
    // ------------------------------------------------------------------

    public function test_ratings_need_the_token(): void
    {
        $this->putJson('/api/sync/ratings', ['items' => []])->assertUnauthorized();
        $this->withToken('wrong')->putJson('/api/sync/reviews', ['items' => []])->assertUnauthorized();
    }

    public function test_ratings_land_on_the_edition_without_touching_its_updated_at(): void
    {
        $book = $this->catalogueBook(edition: ['isbn' => self::ISBN]);
        $edition = $book->editions()->sole();
        $this->setUpdatedAt($edition, '2026-03-01 10:00:00');

        $this->ratings([['isbn' => self::ISBN, 'count' => 3, 'total' => 13], ['isbn' => '9780000000002', 'count' => 1, 'total' => 5]])
            ->assertOk()
            ->assertExactJson(['updated' => 1, 'unknown' => 1]);

        $edition->refresh();
        $this->assertSame(3, $edition->reader_rating_count);
        $this->assertSame(13, $edition->reader_rating_total);
        // A rating is not an edit: the bot's pull feed must not see it as one.
        $this->assertSame('2026-03-01 10:00:00', $edition->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_the_book_page_shows_the_readers_average_over_its_editions(): void
    {
        $book = $this->catalogueBook(edition: ['isbn' => self::ISBN]);
        $this->edition($book, ['isbn' => self::OTHER_ISBN]);

        $this->getJson("/api/books/{$book->id}")->assertOk()->assertJsonPath('data.readerRating', null);

        $this->ratings([
            ['isbn' => self::ISBN, 'count' => 3, 'total' => 13],
            ['isbn' => self::OTHER_ISBN, 'count' => 1, 'total' => 2],
        ])->assertOk();

        $this->getJson("/api/books/{$book->id}")
            ->assertOk()
            ->assertJsonPath('data.readerRating.count', 4)
            ->assertJsonPath('data.readerRating.average', 3.8);
    }

    public function test_a_rating_left_out_of_the_list_is_gone(): void
    {
        $book = $this->catalogueBook(edition: ['isbn' => self::ISBN]);
        $this->ratings([['isbn' => self::ISBN, 'count' => 2, 'total' => 9]])->assertOk();

        $this->ratings([])->assertOk()->assertJsonPath('updated', 1);

        $this->assertSame(0, $book->editions()->sole()->reader_rating_count);
        $this->getJson("/api/books/{$book->id}")->assertJsonPath('data.readerRating', null);
    }

    // ------------------------------------------------------------------
    // Reviews
    // ------------------------------------------------------------------

    public function test_a_new_review_waits_for_a_moderator_before_the_site_shows_it(): void
    {
        $book = $this->catalogueBook(edition: ['isbn' => self::ISBN]);

        $this->reviews([$this->review()])
            ->assertOk()
            ->assertExactJson(['created' => 1, 'updated' => 0, 'removed' => 0, 'unknown' => 0]);

        $review = ReaderReview::sole();
        $this->assertSame(ModerationStatus::Pending, $review->status);
        $this->assertSame($book->id, $review->book_id);
        $this->getJson("/api/books/{$book->id}")->assertJsonCount(0, 'data.readerReviews');

        $review->update(['status' => ModerationStatus::Approved]);

        $this->getJson("/api/books/{$book->id}")
            ->assertJsonCount(1, 'data.readerReviews')
            ->assertJsonPath('data.readerReviews.0.authorName', 'Dilnoza')
            ->assertJsonPath('data.readerReviews.0.rating', 5)
            ->assertJsonPath('data.readerReviews.0.body', 'Juda yoqdi.')
            ->assertJsonPath('data.readerReviews.0.writtenAt', '2026-10-01T10:00:00Z');
    }

    public function test_a_changed_text_goes_back_for_review_but_a_new_date_does_not(): void
    {
        $this->catalogueBook(edition: ['isbn' => self::ISBN]);
        $this->reviews([$this->review()])->assertOk();
        ReaderReview::sole()->update(['status' => ModerationStatus::Approved]);

        $this->reviews([$this->review(['writtenAt' => '2026-10-05T10:00:00Z'])])->assertOk();
        $this->assertSame(ModerationStatus::Approved, ReaderReview::sole()->status);

        $this->reviews([$this->review(['text' => 'Qayta oʻqib, fikrim oʻzgardi.'])])->assertOk()->assertJsonPath('updated', 1);
        $this->assertSame(ModerationStatus::Pending, ReaderReview::sole()->status);
    }

    public function test_a_rejected_review_stays_rejected_when_resent_unchanged(): void
    {
        $this->catalogueBook(edition: ['isbn' => self::ISBN]);
        $this->reviews([$this->review()])->assertOk();
        ReaderReview::sole()->update(['status' => ModerationStatus::Rejected]);

        $this->reviews([$this->review()])->assertOk()->assertJsonPath('updated', 0);
        $this->assertSame(ModerationStatus::Rejected, ReaderReview::sole()->status);
    }

    public function test_a_review_left_out_of_the_list_is_taken_down(): void
    {
        $book = $this->catalogueBook(edition: ['isbn' => self::ISBN]);
        $this->reviews([$this->review(), $this->review(['id' => 's2', 'authorName' => null])])->assertOk();
        ReaderReview::query()->update(['status' => ModerationStatus::Approved]);

        // The reader made it private, or deleted the book, in the bot.
        $this->reviews([$this->review(['id' => 's2', 'authorName' => null])])->assertOk()->assertJsonPath('removed', 1);

        $this->assertSame(['s2'], ReaderReview::pluck('external_id')->all());
        $this->getJson("/api/books/{$book->id}")
            ->assertJsonCount(1, 'data.readerReviews')
            ->assertJsonPath('data.readerReviews.0.authorName', null);
    }

    public function test_a_review_of_a_book_not_here_is_skipped(): void
    {
        $this->reviews([$this->review(['isbn' => '9780000000002'])])
            ->assertOk()
            ->assertJsonPath('unknown', 1);

        $this->assertSame(0, ReaderReview::count());
    }

    public function test_the_body_is_validated(): void
    {
        $this->reviews([$this->review(['rating' => 6])])->assertUnprocessable();
        $this->reviews([$this->review(['text' => ''])])->assertUnprocessable();
        $this->withToken(self::TOKEN)->putJson('/api/sync/reviews', [])->assertUnprocessable();
    }

    // ------------------------------------------------------------------
    // The panel
    // ------------------------------------------------------------------

    public function test_an_admin_publishes_a_review_from_the_queue(): void
    {
        $this->catalogueBook(edition: ['isbn' => self::ISBN]);
        $this->reviews([$this->review()])->assertOk();
        $review = ReaderReview::sole();

        $this->actingAs($this->user(UserRole::Admin));

        Livewire::test(ListReaderReviews::class)
            ->assertCanSeeTableRecords([$review])
            ->callTableAction('publish', $review);

        $this->assertSame(ModerationStatus::Approved, $review->refresh()->status);
    }

    public function test_only_admins_see_the_queue(): void
    {
        $this->actingAs($this->user(UserRole::Taqrizchi));

        $this->get('/admin/reader-reviews')->assertForbidden();
    }

    // ------------------------------------------------------------------

    /** @param  array<string, mixed>  $overrides */
    private function review(array $overrides = []): array
    {
        return [
            'id' => 's1',
            'isbn' => self::ISBN,
            'rating' => 5,
            'text' => 'Juda yoqdi.',
            'authorName' => 'Dilnoza',
            'writtenAt' => '2026-10-01T10:00:00Z',
            ...$overrides,
        ];
    }

    /** @param  list<array<string, mixed>>  $items */
    private function ratings(array $items): TestResponse
    {
        return $this->withToken(self::TOKEN)->putJson('/api/sync/ratings', ['items' => $items]);
    }

    /** @param  list<array<string, mixed>>  $items */
    private function reviews(array $items): TestResponse
    {
        return $this->withToken(self::TOKEN)->putJson('/api/sync/reviews', ['items' => $items]);
    }

    private function user(UserRole $role): User
    {
        return User::create([
            'name' => $role->value,
            'email' => "{$role->value}@example.test",
            'password' => 'password',
            'role' => $role,
        ]);
    }

    private function setUpdatedAt(Edition $edition, string $at): void
    {
        DB::table('editions')->where('id', $edition->id)->update(['updated_at' => $at]);
    }
}
