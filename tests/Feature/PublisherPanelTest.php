<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\PublisherResource;
use App\Filament\Resources\PublisherResource\Pages\CreatePublisher;
use App\Filament\Resources\PublisherResource\Pages\EditPublisher;
use App\Filament\Resources\PublisherResource\Pages\ListPublishers;
use App\Models\Book;
use App\Models\Publisher;
use App\Models\Translator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The publisher catalogue in the admin panel — the same shape of thing as
 * the author catalogue, guarded the same way.
 *
 * @see \Tests\Feature\AuthorPanelTest for why these rows are admin-only to
 *      edit but open for anyone to add.
 */
class PublisherPanelTest extends TestCase
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

    private function translatorUser(): User
    {
        $user = User::create([
            'name' => 'Ilhom',
            'email' => 'ilhom@example.test',
            'password' => 'password',
            'role' => UserRole::Translator,
        ]);

        Translator::create([
            'user_id' => $user->id,
            'slug' => 'ilhom',
            'name' => 'Ilhom',
            'years_active' => 5,
        ]);

        return $user->fresh();
    }

    private function publisher(array $attributes = []): Publisher
    {
        return Publisher::create(array_merge([
            'slug' => 'yangi-asr-avlodi',
            'name' => 'Yangi asr avlodi',
        ], $attributes));
    }

    // ---- an admin running the catalogue ---------------------------------

    public function test_an_admin_sees_the_publisher_list(): void
    {
        $first = $this->publisher();
        $second = $this->publisher(['slug' => 'akademnashr', 'name' => 'Akademnashr']);

        $this->actingAs($this->admin());

        Livewire::test(ListPublishers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$first, $second]);
    }

    public function test_the_list_shows_how_many_books_each_publisher_has(): void
    {
        $publisher = $this->publisher();
        $translator = $this->translatorUser()->translator;

        Book::create([
            'translator_id' => $translator->id,
            'publisher_id' => $publisher->id,
            'uzbek_title' => 'Chol va dengiz',
            'original_title' => 'The Old Man and the Sea',
            'author' => 'Ernest Hemingway',
            'source_language' => 'en',
        ]);

        $this->actingAs($this->admin());

        $listed = PublisherResource::getEloquentQuery()->findOrFail($publisher->id);

        $this->assertSame(1, $listed->books_count);

        Livewire::test(ListPublishers::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('books_count', 1, $listed);
    }

    public function test_an_admin_can_create_a_publisher_in_every_language(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreatePublisher::class)
            ->fillForm([
                'name' => 'Akademnashr',
                'slug' => 'akademnashr',
                'website_url' => 'https://example.test/akademnashr',
                'established_year' => 1991,
                'bio.uz' => 'Toshkentdagi nashriyot.',
                'bio.ru' => 'Издательство в Ташкенте.',
                'bio.en' => 'A Tashkent publishing house.',
                'country.uz' => "O'zbekiston",
                'country.en' => 'Uzbekistan',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $publisher = Publisher::where('slug', 'akademnashr')->sole();

        $this->assertSame('Akademnashr', $publisher->name);
        $this->assertSame(1991, $publisher->established_year);
        $this->assertSame('Издательство в Ташкенте.', $publisher->getTranslation('bio', 'ru'));
        $this->assertSame("O'zbekiston", $publisher->getTranslation('country', 'uz'));
        $this->assertSame('Uzbekistan', $publisher->getTranslation('country', 'en'));
    }

    public function test_a_created_publisher_is_immediately_visible_to_the_site(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreatePublisher::class)
            ->fillForm([
                'name' => 'Akademnashr',
                'slug' => 'akademnashr',
                'country.en' => 'Uzbekistan',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->getJson('/api/publishers?locale=en')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Akademnashr')
            ->assertJsonPath('data.0.slug', 'akademnashr')
            ->assertJsonPath('data.0.country', 'Uzbekistan');
    }

    public function test_an_admin_can_edit_a_publisher(): void
    {
        $publisher = $this->publisher(['bio' => ['uz' => 'Eski matn.']]);

        $this->actingAs($this->admin());

        Livewire::test(EditPublisher::class, ['record' => $publisher->getRouteKey()])
            ->assertFormSet(['name' => 'Yangi asr avlodi', 'slug' => 'yangi-asr-avlodi'])
            ->fillForm([
                'name' => 'Yangi Asr Avlodi',
                'bio.uz' => 'Yangi matn.',
                'established_year' => 1996,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $publisher->refresh();

        $this->assertSame('Yangi Asr Avlodi', $publisher->name);
        $this->assertSame('Yangi matn.', $publisher->getTranslation('bio', 'uz'));
        $this->assertSame(1996, $publisher->established_year);
    }

    public function test_an_admin_can_delete_a_publisher_without_taking_its_books(): void
    {
        $publisher = $this->publisher();
        $translator = $this->translatorUser()->translator;

        $book = Book::create([
            'translator_id' => $translator->id,
            'publisher_id' => $publisher->id,
            'uzbek_title' => 'Chol va dengiz',
            'original_title' => 'The Old Man and the Sea',
            'author' => 'Ernest Hemingway',
            'publisher' => 'Yangi asr avlodi',
            'source_language' => 'en',
        ]);

        $this->actingAs($this->admin());

        Livewire::test(ListPublishers::class)
            ->callTableBulkAction('delete', [$publisher]);

        $this->assertDatabaseMissing('publishers', ['id' => $publisher->id]);

        $book->refresh();
        $this->assertNull($book->publisher_id);
        // The free-text column survives, so the book still names its
        // publisher on the site even with nothing linked.
        $this->assertSame('Yangi asr avlodi', $book->publisher);
    }

    // ---- form rules ------------------------------------------------------

    public function test_a_name_and_slug_are_required(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreatePublisher::class)
            ->fillForm(['name' => '', 'slug' => ''])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'slug' => 'required']);
    }

    public function test_a_slug_must_be_url_safe_and_unique(): void
    {
        $this->publisher();

        $this->actingAs($this->admin());

        Livewire::test(CreatePublisher::class)
            ->fillForm(['name' => 'Another House', 'slug' => 'Not A Slug'])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        Livewire::test(CreatePublisher::class)
            ->fillForm(['name' => 'Another House', 'slug' => 'yangi-asr-avlodi'])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'unique']);
    }

    public function test_a_website_url_must_be_a_url(): void
    {
        $this->actingAs($this->admin());

        // The site renders this straight into an href.
        Livewire::test(CreatePublisher::class)
            ->fillForm(['name' => 'Akademnashr', 'slug' => 'akademnashr', 'website_url' => 'not a url'])
            ->call('create')
            ->assertHasFormErrors(['website_url']);
    }

    // ---- who is allowed in ----------------------------------------------

    public function test_a_translator_cannot_open_the_publisher_catalogue(): void
    {
        $this->actingAs($this->translatorUser());

        $this->get('/admin/publishers')->assertForbidden();
    }

    public function test_a_translator_cannot_edit_a_publisher(): void
    {
        $publisher = $this->publisher();

        $this->actingAs($this->translatorUser());

        $this->get("/admin/publishers/{$publisher->getRouteKey()}/edit")->assertForbidden();
    }

    public function test_a_translator_may_still_add_a_publisher_they_need_for_a_book(): void
    {
        $user = $this->translatorUser();

        $this->actingAs($user);

        $this->assertTrue(Gate::forUser($user)->allows('create', Publisher::class));
        $this->assertTrue(PublisherResource::canCreate());
    }

    public function test_an_admin_is_allowed_everything(): void
    {
        $admin = $this->admin();
        $publisher = $this->publisher();

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', Publisher::class));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $publisher));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $publisher));
    }
}
