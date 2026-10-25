<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\LibraryResource;
use App\Filament\Resources\LibraryResource\Pages\CreateLibrary;
use App\Filament\Resources\LibraryResource\Pages\EditLibrary;
use App\Filament\Resources\LibraryResource\Pages\ListLibraries;
use App\Filament\Resources\LibraryResource\RelationManagers\HoldingsRelationManager;
use App\Filament\Widgets\LibraryProfileWidget;
use App\Models\Book;
use App\Models\Library;
use App\Models\LibraryHolding;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Libraries in the panel: an admin sets one up, its owner keeps its books. */
class LibraryPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-25 12:00:00');

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => 'password', 'role' => UserRole::Admin,
        ]);
    }

    private function owned(string $slug = 'mehr', string $name = 'Mehr kutubxonasi'): Library
    {
        $user = User::create([
            'name' => $name, 'email' => "{$slug}@example.test",
            'password' => 'password', 'role' => UserRole::Library,
        ]);

        return Library::create([
            'user_id' => $user->id, 'slug' => $slug, 'name' => $name,
            'region' => 'Toshkent shahri', 'district' => 'Shayxontohur tumani',
        ]);
    }

    private function holding(Library $library, int $total = 2, int $available = 1, string $title = "O'tkan kunlar"): LibraryHolding
    {
        return $library->holdings()->create([
            'book_id' => Book::create(['uzbek_title' => $title])->id,
            'copies_total' => $total,
            'copies_available' => $available,
        ]);
    }

    public function test_an_admin_sets_up_a_library_with_its_owners_login(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateLibrary::class)
            ->fillForm([
                'email' => 'mehr@example.test',
                'password' => 'secret-pass-1',
                'name' => 'Mehr kutubxonasi',
                'slug' => 'mehr',
                'region' => 'Toshkent shahri',
                'district' => 'Shayxontohur tumani',
                'address' => 'Chorsu, 2-xona',
                'hours' => ['uz' => 'Du–Sha 10:00–20:00'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $library = Library::sole();
        $this->assertSame(UserRole::Library, $library->user->role);
        $this->assertSame('Du–Sha 10:00–20:00', $library->getTranslation('hours', 'uz'));
        $this->assertFalse($library->is_published);
        $this->assertTrue($library->user->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_a_library_login_without_a_library_cannot_sign_in(): void
    {
        $user = User::create([
            'name' => 'Nobody', 'email' => 'nobody@example.test',
            'password' => 'password', 'role' => UserRole::Library,
        ]);

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_an_owner_sees_and_opens_only_their_own_library(): void
    {
        $mine = $this->owned();
        $theirs = $this->owned('idrok', 'Idrok kutubxonasi');

        $this->actingAs($mine->user);

        Livewire::test(ListLibraries::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        $this->get(LibraryResource::getUrl('edit', ['record' => $mine]))->assertOk();
        $this->get(LibraryResource::getUrl('edit', ['record' => $theirs]))->assertNotFound();
        $this->get(LibraryResource::getUrl('create'))->assertForbidden();
    }

    public function test_an_owner_cannot_move_the_url_or_publish_their_library(): void
    {
        $library = $this->owned();

        $this->actingAs($library->user);

        $library->update(['slug' => 'elsewhere', 'is_published' => true, 'address' => 'Yangi manzil']);

        $library->refresh();
        $this->assertSame('mehr', $library->slug);
        $this->assertFalse($library->is_published);
        $this->assertSame('Yangi manzil', $library->address);
    }

    public function test_an_owner_saves_their_page_from_the_form(): void
    {
        $library = $this->owned();

        $this->actingAs($library->user);

        Livewire::test(EditLibrary::class, ['record' => $library->getRouteKey()])
            ->fillForm(['phone' => '+998 90 123 45 67', 'telegram' => '@mehr_kutubxona'])
            ->call('save')
            ->assertHasNoFormErrors();

        $library->refresh();
        $this->assertSame('+998 90 123 45 67', $library->phone);
        $this->assertSame('https://t.me/mehr_kutubxona', $library->telegram_url);
    }

    public function test_lent_out_and_returned_move_the_count_and_never_past_the_ends(): void
    {
        $library = $this->owned();
        $holding = $this->holding($library, total: 2, available: 1);

        $this->actingAs($library->user);

        $table = Livewire::test(HoldingsRelationManager::class, [
            'ownerRecord' => $library,
            'pageClass' => EditLibrary::class,
        ]);

        $table->callTableAction('lend', $holding);
        $this->assertSame(0, $holding->fresh()->copies_available);
        $table->assertTableActionHidden('lend', $holding->fresh());

        $table->callTableAction('giveBack', $holding->fresh());
        $table->callTableAction('giveBack', $holding->fresh());
        $this->assertSame(2, $holding->fresh()->copies_available);
        $table->assertTableActionHidden('giveBack', $holding->fresh());
    }

    public function test_a_holding_never_has_more_on_the_shelf_than_it_owns(): void
    {
        $holding = $this->holding($this->owned(), total: 2, available: 5);

        $this->assertSame(2, $holding->copies_available);

        $holding->update(['copies_total' => 1]);
        $this->assertSame(1, $holding->fresh()->copies_available);
    }

    public function test_an_owner_adds_a_book_and_cannot_add_it_twice(): void
    {
        $library = $this->owned();
        $book = Book::create(['uzbek_title' => 'Kecha va kunduz']);

        $this->actingAs($library->user);

        $table = Livewire::test(HoldingsRelationManager::class, [
            'ownerRecord' => $library,
            'pageClass' => EditLibrary::class,
        ]);

        $table->callTableAction('create', data: ['book_id' => $book->id, 'copies_total' => 3, 'copies_available' => 3])
            ->assertHasNoTableActionErrors();
        $this->assertSame(3, $library->holdings()->sole()->copies_total);

        $table->callTableAction('create', data: ['book_id' => $book->id, 'copies_total' => 1, 'copies_available' => 1])
            ->assertHasTableActionErrors(['book_id' => 'unique']);
    }

    public function test_a_holding_change_stamps_the_list_and_pings_the_site(): void
    {
        config(['seo.revalidate.url' => 'https://site.test/api/revalidate', 'seo.revalidate.secret' => 'secret']);
        Http::fake();

        $library = $this->owned();
        $holding = $this->holding($library);
        Http::fake();

        CarbonImmutable::setTestNow('2026-10-26 09:00:00');
        $holding->lend();

        $this->assertSame('2026-10-26 09:00:00', $library->fresh()->holdings_updated_at->toDateTimeString());
        Http::assertSent(fn ($request) => $request['type'] === 'library' && $request['slug'] === 'mehr');
    }

    public function test_the_owners_dashboard_leads_to_their_books(): void
    {
        $library = $this->owned();
        $this->holding($library, available: 0);

        $this->actingAs($library->user);

        $this->assertTrue(LibraryProfileWidget::canView());
        Livewire::test(LibraryProfileWidget::class)
            ->assertSee('Mehr kutubxonasi')
            ->assertSee('1 with every copy lent out')
            ->assertSee(LibraryResource::getUrl('edit', ['record' => $library]));

        $this->actingAs($this->admin);
        $this->assertFalse(LibraryProfileWidget::canView());
    }

    public function test_admin_screens_open(): void
    {
        $library = $this->owned();
        $this->holding($library);

        $this->actingAs($this->admin);

        $this->get('/admin/libraries')->assertOk()->assertSee('Mehr kutubxonasi');
        $this->get('/admin/libraries/create')->assertOk();
        $this->get(LibraryResource::getUrl('edit', ['record' => $library]))->assertOk();
    }
}
