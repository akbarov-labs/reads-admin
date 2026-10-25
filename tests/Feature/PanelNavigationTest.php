<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\InquiryResource;
use App\Filament\Resources\TaqrizResource;
use App\Filament\Widgets\CatalogueOverviewWidget;
use App\Filament\Widgets\NeedsAttentionWidget;
use App\Models\Book;
use App\Models\Taqriz;
use App\Models\Taqrizchi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The sidebar's groups and counts, and what the admin's dashboard opens on. */
class PanelNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $reviewer;

    private Taqrizchi $taqrizchi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'a@example.test',
            'password' => 'password', 'role' => UserRole::Admin,
        ]);
        $this->reviewer = User::create([
            'name' => 'Nodira', 'email' => 'n@example.test',
            'password' => 'password', 'role' => UserRole::Taqrizchi,
        ]);
        $this->taqrizchi = Taqrizchi::create(['user_id' => $this->reviewer->id, 'slug' => 'nodira', 'name' => 'Nodira']);
    }

    public function test_the_sidebar_is_grouped_with_books_first_in_the_catalogue(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertSeeInOrder(['Dashboard', 'Site stats', 'Inquiries', 'Catalogue', 'Books', 'Authors', 'Translators', 'Publishers', 'Reviews', 'Taqrizlar', 'Reader reviews', 'Taqrizchilar', 'On the site', 'Collections', 'Quotes', 'Announcements']);
    }

    public function test_pending_counts_are_shown_to_admins_only(): void
    {
        $this->pendingTaqriz();

        $this->actingAs($this->admin);
        $this->assertSame('1', TaqrizResource::getNavigationBadge());

        $this->actingAs($this->reviewer);
        $this->assertNull(TaqrizResource::getNavigationBadge());
    }

    public function test_a_reviewer_does_not_get_the_inquiries_entry(): void
    {
        $this->actingAs($this->reviewer);
        $this->assertFalse(InquiryResource::shouldRegisterNavigation());

        $this->actingAs($this->admin);
        $this->assertTrue(InquiryResource::shouldRegisterNavigation());
    }

    public function test_the_admin_dashboard_shows_what_is_waiting_and_the_catalogue(): void
    {
        $this->pendingTaqriz();

        $this->actingAs($this->admin);

        Livewire::test(NeedsAttentionWidget::class)
            ->assertSee('Taqrizlar to review')
            ->assertSee('Waiting for you')
            ->assertSee('tableFilters%5Bstatus%5D%5Bvalue%5D=pending', escape: false);
        Livewire::test(CatalogueOverviewWidget::class)->assertSee('Books')->assertSee('Publishers');
    }

    public function test_the_dashboard_widgets_are_the_admins(): void
    {
        $this->actingAs($this->reviewer);

        $this->assertFalse(NeedsAttentionWidget::canView());
        $this->assertFalse(CatalogueOverviewWidget::canView());
    }

    private function pendingTaqriz(): Taqriz
    {
        $edition = Book::create(['uzbek_title' => "O'tkan kunlar"])->editions()->create([]);

        return Taqriz::create(['taqrizchi_id' => $this->taqrizchi->id, 'edition_id' => $edition->id, 'body' => 'Yaxshi']);
    }
}
