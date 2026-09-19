<?php

namespace Tests\Feature;

use App\Enums\ModerationStatus;
use App\Enums\UserRole;
use App\Filament\Resources\PublisherResource\Pages\ListPublishers;
use App\Models\Book;
use App\Models\Publisher;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Publishers signing in to maintain their own catalogue entry.
 *
 * The distinction that matters here is between a publisher row that somebody
 * owns and one that nobody does. Most rows were backfilled from the old
 * free-text `publisher` column on books and have no account attached; those
 * stay admin-only, and "owned by nobody" must never accidentally read as
 * "owned by whoever is asking".
 */
class PublisherAccountTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => UserRole::Admin,
        ]);
    }

    /** A publisher with a login attached. */
    private function claimed(string $slug = 'yangi-asr', string $name = 'Yangi asr avlodi'): Publisher
    {
        $user = User::create([
            'name' => $name,
            'email' => "{$slug}@example.test",
            'password' => 'password',
            'role' => UserRole::Publisher,
        ]);

        return Publisher::create([
            'user_id' => $user->id,
            'slug' => $slug,
            'name' => $name,
        ]);
    }

    /** A row backfilled from the old column, with nobody signing in for it. */
    private function unclaimed(string $slug = 'nihol', string $name = 'Nihol'): Publisher
    {
        return Publisher::create(['slug' => $slug, 'name' => $name]);
    }

    public function test_a_publisher_with_a_profile_can_access_the_panel(): void
    {
        $publisher = $this->claimed();

        $this->assertTrue($publisher->user->fresh()->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_a_publisher_account_with_no_profile_cannot(): void
    {
        $user = User::create([
            'name' => 'Nobody',
            'email' => 'nobody@example.test',
            'password' => 'password',
            'role' => UserRole::Publisher,
        ]);

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_a_publisher_sees_only_their_own_row(): void
    {
        $mine = $this->claimed('yangi-asr', 'Yangi asr avlodi');
        $theirs = $this->claimed('asaxiy', 'Asaxiy Books');
        $orphan = $this->unclaimed();

        $this->actingAs($mine->user);

        Livewire::test(ListPublishers::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs, $orphan]);
    }

    public function test_a_publisher_may_edit_their_own_row_but_not_anothers(): void
    {
        $mine = $this->claimed('yangi-asr', 'Yangi asr avlodi');
        $theirs = $this->claimed('asaxiy', 'Asaxiy Books');

        $user = $mine->user;

        $this->assertTrue(Gate::forUser($user)->allows('update', $mine));
        $this->assertFalse(Gate::forUser($user)->allows('update', $theirs));
    }

    public function test_an_unclaimed_row_belongs_to_nobody(): void
    {
        $orphan = $this->unclaimed();
        $publisher = $this->claimed();

        // The guard that matters: user_id is null on both sides of the
        // comparison for a user with no publisher, and null == null must not
        // hand the row to a stranger.
        $this->assertFalse(Gate::forUser($publisher->user)->allows('update', $orphan));
        $this->assertTrue(Gate::forUser($this->admin())->allows('update', $orphan));
    }

    public function test_a_publisher_cannot_move_their_own_public_url(): void
    {
        $publisher = $this->claimed();

        $this->actingAs($publisher->user);

        $publisher->slug = 'something-else';
        $publisher->website_url = 'https://example.test';
        $publisher->save();

        $fresh = $publisher->fresh();

        $this->assertSame('yangi-asr', $fresh->slug);
        // The legitimate half of the save still goes through.
        $this->assertSame('https://example.test', $fresh->website_url);
    }

    public function test_a_publisher_cannot_reassign_their_row_to_another_account(): void
    {
        $publisher = $this->claimed();
        $other = $this->admin();
        $originalUserId = $publisher->user_id;

        $this->actingAs($publisher->user);

        $publisher->user_id = $other->id;
        $publisher->save();

        $this->assertSame($originalUserId, $publisher->fresh()->user_id);
    }

    public function test_a_book_a_publisher_adds_goes_live_immediately(): void
    {
        $publisher = $this->claimed();

        $this->actingAs($publisher->user);

        $book = $publisher->books()->create([
            'uzbek_title' => 'Chol va dengiz',
            'original_title' => 'The Old Man and the Sea',
            'author' => 'Ernest Hemingway',
            'source_language' => 'en',
            'year' => 1952,
        ]);

        // Unlike a taqrizchi adding somebody else's book in order to review
        // it, a publisher describing their own catalogue is the source for it.
        $this->assertSame(ModerationStatus::Approved, $book->fresh()->status);
        $this->assertNull($book->translator_id);

        $this->getJson('/api/books')
            ->assertOk()
            ->assertJsonPath('data.0.publisherId', (string) $publisher->id);
    }

    public function test_a_publisher_book_needs_no_translator(): void
    {
        $publisher = $this->claimed();

        $book = Book::create([
            'publisher_id' => $publisher->id,
            'uzbek_title' => 'Asl asar',
            'original_title' => 'An Original Work',
            'author' => 'Someone',
            'source_language' => 'uz',
        ]);

        $this->getJson("/api/books/{$book->id}")
            ->assertOk()
            ->assertJsonPath('data.translator', null);
    }
}
