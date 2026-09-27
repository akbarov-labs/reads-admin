<?php

namespace Tests\Feature;

use App\Enums\BookRole;
use App\Enums\ModerationStatus;
use App\Enums\UserRole;
use App\Filament\Resources\BookResource\Pages\EditBook;
use App\Filament\Resources\BookResource\Pages\ListBooks;
use App\Filament\Resources\PublisherResource\Pages\EditPublisher;
use App\Filament\Resources\PublisherResource\RelationManagers\EditionsRelationManager as PublisherEditions;
use App\Filament\Resources\TranslatorResource\Pages\EditTranslator;
use App\Filament\Resources\TranslatorResource\RelationManagers\EditionsRelationManager as TranslatorEditions;
use App\Models\Edition;
use App\Models\User;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DetachAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * The three places an edition is entered: a translator's portfolio, a
 * publisher's list, and the admin's Books screen.
 */
class EditionPanelTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => UserRole::Admin]);
    }

    public function test_a_translator_adds_their_translation_of_an_existing_book(): void
    {
        $translator = $this->translatorWithAccount();
        $book = $this->catalogueBook(credits: []);

        $this->actingAs($translator->user);

        Livewire::test(TranslatorEditions::class, ['ownerRecord' => $translator, 'pageClass' => EditTranslator::class])
            ->callTableAction(CreateAction::class, data: [
                'book_id' => $book->id,
                'role' => BookRole::Translator->value,
                'publisher_id' => $this->publisher()->id,
                'isbn' => '978-9943-23-198-6',
                'year' => 2023,
            ])
            ->assertHasNoTableActionErrors();

        $edition = $translator->editions()->where('isbn', '9789943231986')->sole();

        $this->assertSame($book->id, $edition->book_id);
        $this->assertSame(BookRole::Translator, $edition->pivot->role);
        $this->assertSame(2, $book->editions()->count());
    }

    public function test_removing_an_edition_from_a_portfolio_keeps_it_for_everyone_else(): void
    {
        $translator = $this->translatorWithAccount();
        $colleague = $this->translatorWithAccount('dilnoza', 'Dilnoza');
        $edition = $this->catalogueBook(credits: [$translator, $colleague])->editions()->first();

        $this->actingAs($translator->user);

        Livewire::test(TranslatorEditions::class, ['ownerRecord' => $translator, 'pageClass' => EditTranslator::class])
            ->callTableAction(DetachAction::class, $edition);

        $this->assertNotNull($edition->fresh());
        $this->assertSame(['dilnoza'], $edition->translators()->pluck('slug')->all());
    }

    public function test_a_publisher_adds_an_edition_with_its_translators(): void
    {
        $user = User::create(['name' => 'Asaxiy', 'email' => 'asaxiy@example.test', 'password' => 'password', 'role' => UserRole::Publisher]);
        $publisher = $this->publisher('Asaxiy Books');
        $publisher->update(['user_id' => $user->id]);
        $first = $this->translatorWithAccount();
        $second = $this->translatorWithAccount('dilnoza', 'Dilnoza');
        $book = $this->catalogueBook(credits: []);

        $this->actingAs($user);

        Livewire::test(PublisherEditions::class, ['ownerRecord' => $publisher, 'pageClass' => EditPublisher::class])
            ->callTableAction(CreateAction::class, data: [
                'book_id' => $book->id,
                'credits' => [
                    ['translator_id' => $first->id, 'role' => BookRole::Translator->value],
                    ['translator_id' => $second->id, 'role' => BookRole::Editor->value],
                ],
                'year' => 2024,
            ])
            ->assertHasNoTableActionErrors();

        $edition = Edition::where('publisher_id', $publisher->id)->sole();

        $this->assertSame(ModerationStatus::Approved, $edition->status);
        $this->assertSame(['ilhom', 'dilnoza'], $edition->translators->pluck('slug')->all());
        $this->assertSame(BookRole::Editor, $edition->translators[1]->pivot->role);
    }

    public function test_an_admin_can_open_the_books_screens(): void
    {
        $book = $this->catalogueBook(['status' => ModerationStatus::Pending]);

        $this->actingAs($this->admin());

        Livewire::test(ListBooks::class)->assertCanSeeTableRecords([$book]);
        Livewire::test(EditBook::class, ['record' => $book->getRouteKey()])->assertSuccessful();

        $this->get('/admin/books')->assertOk();
        $this->get("/admin/books/{$book->id}/edit")->assertOk();
    }

    public function test_the_books_screen_is_admin_only(): void
    {
        $translator = $this->translatorWithAccount();

        $this->actingAs($translator->user);

        $this->get('/admin/books')->assertForbidden();
    }
}
