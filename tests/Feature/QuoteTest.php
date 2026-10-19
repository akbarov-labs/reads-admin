<?php

namespace Tests\Feature;

use App\Enums\ModerationStatus;
use App\Enums\UserRole;
use App\Filament\Resources\QuoteResource\Pages\CreateQuote;
use App\Filament\Resources\QuoteResource\Pages\ListQuotes;
use App\Models\Quote;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/** Lines from books: on each book's page, and one a day on the home page. */
class QuoteTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    public function test_a_book_page_lists_its_published_quotes_newest_first_with_the_translators(): void
    {
        $translator = $this->translatorWithAccount('ibrohim', 'Ibrohim Gʻafurov');
        $book = $this->catalogueBook(edition: ['isbn' => '9789943231986'], credits: [$translator]);
        $edition = $book->editions()->sole();

        Quote::create(['book_id' => $book->id, 'body' => 'Inson yengilish uchun yaratilmagan.', 'page' => 103, 'edition_id' => $edition->id]);
        Quote::create(['book_id' => $book->id, 'body' => 'Dengiz — ayol.']);
        Quote::create(['book_id' => $book->id, 'body' => 'Yashirilgan.', 'status' => ModerationStatus::Rejected]);

        $this->getJson("/api/books/{$book->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.quotes')
            ->assertJsonPath('data.quotes.0.body', 'Dengiz — ayol.')
            ->assertJsonPath('data.quotes.0.translators', [])
            ->assertJsonPath('data.quotes.1.page', 103)
            ->assertJsonPath('data.quotes.1.translators', ['Ibrohim Gʻafurov']);
    }

    public function test_the_quote_of_the_day_turns_once_a_day_through_every_published_quote(): void
    {
        $this->getJson('/api/quotes/today')->assertOk()->assertExactJson(['data' => null]);

        $book = $this->catalogueBook(['uzbek_title' => 'Chol va dengiz']);
        $hidden = $this->catalogueBook(['uzbek_title' => 'Yashirin', 'status' => ModerationStatus::Pending]);
        $first = Quote::create(['book_id' => $book->id, 'body' => 'Birinchi.']);
        $second = Quote::create(['book_id' => $book->id, 'body' => 'Ikkinchi.']);
        Quote::create(['book_id' => $book->id, 'body' => 'Yashirilgan.', 'status' => ModerationStatus::Rejected]);
        Quote::create(['book_id' => $hidden->id, 'body' => 'Chop etilmagan kitobdan.']);

        $day = CarbonImmutable::parse('2026-10-19 09:00', config('app.timezone'));
        $today = Quote::ofTheDay($day);
        $tomorrow = Quote::ofTheDay($day->addDay());

        $this->assertContains($today->id, [$first->id, $second->id]);
        $this->assertNotSame($today->id, $tomorrow->id, 'a new one each day');
        $this->assertSame($today->id, Quote::ofTheDay($day->setTime(23, 59))->id, 'the same all day');
        $this->assertSame($today->id, Quote::ofTheDay($day->addDays(2))->id, 'and round again');

        $this->travelTo($day);
        $this->getJson('/api/quotes/today')
            ->assertOk()
            ->assertJsonPath('data.id', (string) $today->id)
            ->assertJsonPath('data.book.uzbekTitle', 'Chol va dengiz')
            ->assertJsonPath('data.book.author', 'Ernest Hemingway');
    }

    public function test_an_admin_adds_a_quote_and_nobody_else_sees_the_screen(): void
    {
        $book = $this->catalogueBook();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => UserRole::Admin]);

        $this->actingAs($admin);
        Livewire::test(CreateQuote::class)
            ->fillForm(['book_id' => $book->id, 'body' => 'Inson yengilish uchun yaratilmagan.', 'page' => 103])
            ->call('create')
            ->assertHasNoFormErrors();

        $quote = Quote::sole();
        $this->assertSame(ModerationStatus::Approved, $quote->status, 'published when saved');
        $this->assertSame(103, $quote->page);

        $translator = $this->translatorWithAccount('ilhom', 'Ilhom');
        $this->actingAs($translator->user);
        Livewire::test(ListQuotes::class)->assertForbidden();
    }
}
