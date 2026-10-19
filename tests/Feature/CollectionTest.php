<?php

namespace Tests\Feature;

use App\Enums\ModerationStatus;
use App\Enums\UserRole;
use App\Filament\Resources\CollectionResource\Pages\CreateCollection;
use App\Filament\Resources\CollectionResource\Pages\ListCollections;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/** Editors' collections around translation: the list, one collection, the sitemap and the panel. */
class CollectionTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    public function test_published_collections_are_listed_in_order_with_their_count_and_covers(): void
    {
        $first = $this->catalogueBook(['uzbek_title' => 'Chol va dengiz'], ['isbn' => '9789943000001', 'cover_path' => 'covers/a.webp']);
        $second = $this->catalogueBook(['uzbek_title' => 'Alkimyogar'], ['isbn' => '9789943000002']);
        $pending = $this->catalogueBook(['uzbek_title' => 'Kutilmoqda', 'status' => ModerationStatus::Pending], ['isbn' => '9789943000003']);

        $best = Collection::create(['slug' => 'yil-tarjimalari', 'title' => ['uz' => 'Yil tarjimalari', 'ru' => 'Переводы года'], 'is_published' => true, 'position' => 2]);
        $best->items()->createMany([
            ['book_id' => $first->id, 'position' => 0],
            ['book_id' => $second->id, 'position' => 1],
            ['book_id' => $pending->id, 'position' => 2],
        ]);
        Collection::create(['slug' => 'birinchi', 'title' => ['uz' => 'Birinchi'], 'is_published' => true, 'position' => 1]);
        Collection::create(['slug' => 'qoralama', 'title' => ['uz' => 'Qoralama'], 'is_published' => false]);

        $this->getJson('/api/collections?locale=ru')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'birinchi')
            ->assertJsonPath('data.1.title', 'Переводы года')
            ->assertJsonPath('data.1.bookCount', 2)
            ->assertJsonPath('data.1.coverUrls', ['/storage/covers/a.webp'])
            ->assertJsonMissingPath('data.1.items');
    }

    public function test_a_collection_page_has_its_published_books_in_order_with_the_translation_and_note(): void
    {
        $translator = $this->translatorWithAccount('ibrohim', 'Ibrohim Gʻafurov');
        $book = $this->catalogueBook(['uzbek_title' => 'Chol va dengiz'], ['isbn' => '9789943000001'], credits: [$translator]);
        $other = $this->catalogueBook(['uzbek_title' => 'Alkimyogar'], ['isbn' => '9789943000002']);

        $collection = Collection::create(['slug' => 'yil-tarjimalari', 'title' => ['uz' => 'Yil tarjimalari'], 'description' => ['uz' => 'Eng yaxshilari.'], 'is_published' => true]);
        $collection->items()->createMany([
            ['book_id' => $other->id, 'position' => 1],
            ['book_id' => $book->id, 'edition_id' => $book->editions()->sole()->id, 'note' => 'Gʻafurovning eng yaxshi tarjimasi.', 'position' => 0],
        ]);

        $this->getJson('/api/collections/yil-tarjimalari')
            ->assertOk()
            ->assertJsonPath('data.title', 'Yil tarjimalari')
            ->assertJsonPath('data.description', 'Eng yaxshilari.')
            ->assertJsonPath('data.items.0.book.uzbekTitle', 'Chol va dengiz')
            ->assertJsonPath('data.items.0.note', 'Gʻafurovning eng yaxshi tarjimasi.')
            ->assertJsonPath('data.items.0.edition.translators', ['Ibrohim Gʻafurov'])
            ->assertJsonPath('data.items.1.book.uzbekTitle', 'Alkimyogar')
            ->assertJsonPath('data.items.1.edition', null);
    }

    public function test_an_unpublished_or_unknown_collection_is_a_404_and_only_published_ones_are_in_the_sitemap(): void
    {
        Collection::create(['slug' => 'qoralama', 'title' => ['uz' => 'Qoralama'], 'is_published' => false]);
        Collection::create(['slug' => 'tayyor', 'title' => ['uz' => 'Tayyor'], 'is_published' => true]);

        $this->getJson('/api/collections/qoralama')->assertNotFound();
        $this->getJson('/api/collections/yoq')->assertNotFound();

        $this->getJson('/api/sitemap')->assertOk()->assertJsonCount(1, 'collections')->assertJsonPath('collections.0.slug', 'tayyor');
    }

    public function test_an_admin_makes_a_collection_and_others_cannot_open_the_screen(): void
    {
        $book = $this->catalogueBook(['uzbek_title' => 'Chol va dengiz'], ['isbn' => '9789943000001']);
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => UserRole::Admin]));

        Livewire::test(CreateCollection::class)
            ->fillForm([
                'title' => ['uz' => 'Yil tarjimalari', 'ru' => null, 'en' => null],
                'is_published' => true,
                'items' => [['book_id' => $book->id, 'note' => 'Klassika.']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $collection = Collection::sole();
        $this->assertSame('yil-tarjimalari', $collection->slug, 'made from the Uzbek title');
        $this->assertSame([$book->id], $collection->items->pluck('book_id')->all());

        $translator = $this->translatorWithAccount('ilhom', 'Ilhom');
        $this->actingAs($translator->user);
        Livewire::test(ListCollections::class)->assertForbidden();
    }
}
