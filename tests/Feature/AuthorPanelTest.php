<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\AuthorResource;
use App\Filament\Resources\AuthorResource\Pages\CreateAuthor;
use App\Filament\Resources\AuthorResource\Pages\EditAuthor;
use App\Filament\Resources\AuthorResource\Pages\ListAuthors;
use App\Models\Author;
use App\Models\Book;
use App\Models\Translator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The author catalogue in the admin panel.
 *
 * Two things are being pinned here. The first is ordinary CRUD — a row an
 * admin creates has to come back out of /api/authors looking the way the
 * site expects, in all three languages.
 *
 * The second is who may do it. Authors are shared: one row carries the
 * credit on the books of every translator who has worked on that writer, so
 * an edit here is an edit to other people's profiles. AuthorResource has
 * always declared itself admin-only through shouldRegisterNavigation(), but
 * that only hides the sidebar link — and the sidebar is hidden from
 * translators by CSS anyway. AuthorPolicy is what actually enforces it.
 */
class AuthorPanelTest extends TestCase
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

    private function author(array $attributes = []): Author
    {
        return Author::create(array_merge([
            'slug' => 'ernest-hemingway',
            'name' => 'Ernest Hemingway',
        ], $attributes));
    }

    // ---- an admin running the catalogue ---------------------------------

    public function test_an_admin_sees_the_author_list(): void
    {
        $hemingway = $this->author();
        $pamuk = $this->author(['slug' => 'orhan-pamuk', 'name' => 'Orhan Pamuk']);

        $this->actingAs($this->admin());

        Livewire::test(ListAuthors::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$hemingway, $pamuk]);
    }

    public function test_the_list_shows_how_many_books_each_author_has(): void
    {
        $author = $this->author();
        $translator = Translator::first() ?? $this->translatorUser()->translator;

        Book::create([
            'translator_id' => $translator->id,
            'author_id' => $author->id,
            'uzbek_title' => 'Chol va dengiz',
            'original_title' => 'The Old Man and the Sea',
            'author' => 'Ernest Hemingway',
            'source_language' => 'en',
        ]);

        $this->actingAs($this->admin());

        // books_count comes from getEloquentQuery()'s withCount, so the
        // column costs one query for the page rather than one per row.
        // Read the record back through that query, not the plain model —
        // the count only exists on rows the resource loaded.
        $listed = AuthorResource::getEloquentQuery()->findOrFail($author->id);

        $this->assertSame(1, $listed->books_count);

        Livewire::test(ListAuthors::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('books_count', 1, $listed);
    }

    public function test_an_admin_can_create_an_author_in_every_language(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateAuthor::class)
            ->fillForm([
                'name' => 'Orhan Pamuk',
                'slug' => 'orhan-pamuk',
                'website_url' => 'https://example.test/pamuk',
                'birth_year' => 1952,
                'bio.uz' => 'Turk yozuvchisi.',
                'bio.ru' => 'Турецкий писатель.',
                'bio.en' => 'Turkish novelist.',
                'nationality.uz' => 'Turk',
                'nationality.en' => 'Turkish',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $author = Author::where('slug', 'orhan-pamuk')->sole();

        $this->assertSame('Orhan Pamuk', $author->name);
        $this->assertSame(1952, $author->birth_year);
        $this->assertSame('Turk yozuvchisi.', $author->getTranslation('bio', 'uz'));
        $this->assertSame('Турецкий писатель.', $author->getTranslation('bio', 'ru'));
        $this->assertSame('Turkish novelist.', $author->getTranslation('bio', 'en'));
        $this->assertSame('Turkish', $author->getTranslation('nationality', 'en'));
    }

    public function test_a_created_author_is_immediately_visible_to_the_site(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateAuthor::class)
            ->fillForm([
                'name' => 'Orhan Pamuk',
                'slug' => 'orhan-pamuk',
                'bio.en' => 'Turkish novelist.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // The round trip is the point: the panel writes it, the site reads
        // it, and nothing in between renames a field.
        $this->getJson('/api/authors?locale=en')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Orhan Pamuk')
            ->assertJsonPath('data.0.slug', 'orhan-pamuk')
            ->assertJsonPath('data.0.bio', 'Turkish novelist.');
    }

    public function test_an_admin_can_edit_an_author(): void
    {
        $author = $this->author(['bio' => ['uz' => 'Eski matn.']]);

        $this->actingAs($this->admin());

        Livewire::test(EditAuthor::class, ['record' => $author->getRouteKey()])
            ->assertFormSet(['name' => 'Ernest Hemingway', 'slug' => 'ernest-hemingway'])
            ->fillForm([
                'name' => 'Ernest Miller Hemingway',
                'bio.uz' => 'Yangi matn.',
                'death_year' => 1961,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $author->refresh();

        $this->assertSame('Ernest Miller Hemingway', $author->name);
        $this->assertSame('Yangi matn.', $author->getTranslation('bio', 'uz'));
        $this->assertSame(1961, $author->death_year);
    }

    public function test_an_admin_can_delete_an_author_without_taking_its_books(): void
    {
        $author = $this->author();
        $translator = $this->translatorUser()->translator;

        $book = Book::create([
            'translator_id' => $translator->id,
            'author_id' => $author->id,
            'uzbek_title' => 'Chol va dengiz',
            'original_title' => 'The Old Man and the Sea',
            'author' => 'Ernest Hemingway',
            'source_language' => 'en',
        ]);

        $this->actingAs($this->admin());

        Livewire::test(ListAuthors::class)
            ->callTableBulkAction('delete', [$author]);

        $this->assertDatabaseMissing('authors', ['id' => $author->id]);

        // nullOnDelete — a tidy-up in the catalogue must not delete a
        // translator's work.
        $this->assertNotNull($book->fresh());
        $this->assertNull($book->fresh()->author_id);
    }

    // ---- form rules ------------------------------------------------------

    public function test_a_name_and_slug_are_required(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateAuthor::class)
            ->fillForm(['name' => '', 'slug' => ''])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'slug' => 'required']);
    }

    public function test_a_slug_must_be_url_safe_and_unique(): void
    {
        $this->author();

        $this->actingAs($this->admin());

        Livewire::test(CreateAuthor::class)
            ->fillForm(['name' => 'Someone Else', 'slug' => 'Not A Slug'])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        Livewire::test(CreateAuthor::class)
            ->fillForm(['name' => 'Someone Else', 'slug' => 'ernest-hemingway'])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'unique']);
    }

    public function test_editing_an_author_does_not_collide_with_its_own_slug(): void
    {
        $author = $this->author();

        $this->actingAs($this->admin());

        // unique(ignoreRecord: true) — otherwise no author could ever be
        // saved twice.
        Livewire::test(EditAuthor::class, ['record' => $author->getRouteKey()])
            ->fillForm(['name' => 'Ernest Miller Hemingway', 'slug' => 'ernest-hemingway'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    // ---- who is allowed in ----------------------------------------------

    public function test_a_translator_cannot_open_the_author_catalogue(): void
    {
        $this->actingAs($this->translatorUser());

        $this->get('/admin/authors')->assertForbidden();
    }

    public function test_a_translator_cannot_edit_an_author(): void
    {
        $author = $this->author();

        $this->actingAs($this->translatorUser());

        // Renaming a shared row rewrites the credit under books belonging
        // to translators who have never met this one.
        $this->get("/admin/authors/{$author->getRouteKey()}/edit")->assertForbidden();
    }

    public function test_a_translator_may_still_add_an_author_they_need_for_a_book(): void
    {
        $user = $this->translatorUser();

        $this->actingAs($user);

        // The author picker on the book form creates rows inline
        // (BooksRelationManager's createOptionForm). Without this a
        // translator could not record a book by a writer nobody has entered
        // yet — and adding a row, unlike editing one, cannot damage anyone
        // else's data.
        $this->assertTrue(Gate::forUser($user)->allows('create', Author::class));
        $this->assertTrue(AuthorResource::canCreate());
    }

    public function test_an_admin_is_allowed_everything(): void
    {
        $admin = $this->admin();
        $author = $this->author();

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', Author::class));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $author));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $author));
    }
}
