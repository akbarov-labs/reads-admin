<?php

namespace Tests\Feature;

use App\Enums\ModerationStatus;
use App\Enums\UserRole;
use App\Filament\Resources\DealResource\Pages\CreateDeal;
use App\Filament\Resources\DealResource\Pages\ListDeals;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The Deals queue: a shop's promotion typed in, and a draft published from the list. */
class DealPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'a@example.test',
            'password' => 'password', 'role' => UserRole::Admin,
        ]));
    }

    public function test_an_admin_types_in_a_shops_deal(): void
    {
        Livewire::test(CreateDeal::class)
            ->fillForm([
                'kind' => 'discount',
                'status' => ModerationStatus::Approved->value,
                'shop_name' => 'Qamar',
                'title' => 'Klassika kitoblariga 20% chegirma',
                'discount_percent' => 20,
                'promo_code' => 'klassika20',
                'url' => 'https://qamar.uz/aksiya',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $deal = Deal::query()->sole();
        $this->assertSame('Qamar', $deal->sourceName());
        $this->assertSame('KLASSIKA20', $deal->promo_code);
    }

    public function test_a_deal_needs_a_publisher_or_a_shop(): void
    {
        Livewire::test(CreateDeal::class)
            ->fillForm(['kind' => 'discount', 'status' => ModerationStatus::Pending->value, 'title' => 'Chegirma'])
            ->call('create')
            ->assertHasFormErrors(['shop_name' => 'required']);
    }

    public function test_a_draft_is_published_from_the_list(): void
    {
        $deal = Deal::create(['title' => 'Chegirma', 'shop_name' => 'Asaxiy']);

        Livewire::test(ListDeals::class)
            ->assertCanSeeTableRecords([$deal])
            ->callTableAction('approve', $deal);

        $this->assertSame(ModerationStatus::Approved, $deal->fresh()->status);
    }
}
