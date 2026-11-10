<?php

namespace Tests\Feature;

use App\Enums\ModerationStatus;
use App\Enums\UserRole;
use App\Filament\Resources\PublisherResource\Pages\ListPublishers;
use App\Filament\Resources\PublisherSuggestionResource\Pages\ListPublisherSuggestions;
use App\Models\Publisher;
use App\Models\PublisherSuggestion;
use App\Models\User;
use App\Services\PublisherSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Details found for publishers wait in Publisher suggestions until an
 * admin publishes them; nothing reaches a publisher's page before that.
 */
class PublisherSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Http::fake([
            't.me/munirnashr' => Http::response('<img class="tgme_page_photo_image" src="https://cdn4.telesco.pe/file/munir.jpg">'),
            't.me/*' => Http::response('<div class="tgme_page">no photo</div>'),
            'cdn4.telesco.pe/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->file = tempnam(sys_get_temp_dir(), 'suggestions');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => UserRole::Admin]);
    }

    private function load(array $entries): void
    {
        file_put_contents($this->file, json_encode($entries));

        $this->artisan('publishers:import-suggestions', ['--file' => $this->file])->assertSuccessful();
    }

    private function munirEntry(array $overrides = []): array
    {
        return [
            'slug' => 'munir',
            'telegram_channel' => '@munirnashr',
            'confidence' => 'high',
            'evidence' => 'Title "Munir nashriyoti", 12k subscribers, posts its own books.',
            'subscribers' => 12034,
            'order_contact' => 'https://t.me/munir_admin',
            'website_url' => 'https://munir.uz',
            'phone' => '+998 90 123 45 67',
            'instagram' => 'https://www.instagram.com/munir.nashr/',
            'address' => 'Toshkent, Navoiy 1',
            'established_year' => 2012,
            'bio' => ['uz' => 'Diniy kitoblar.', 'ru' => 'Религиозные книги.', 'en' => 'Religious books.'],
            'note' => null,
            ...$overrides,
        ];
    }

    public function test_found_details_wait_for_review_and_do_not_touch_the_page(): void
    {
        $munir = Publisher::create(['slug' => 'munir', 'name' => 'Munir']);
        Publisher::create(['slug' => 'adabiyot', 'name' => 'Adabiyot']);

        file_put_contents($this->file, json_encode([
            $this->munirEntry(['website_url' => 'javascript:alert(1)', 'established_year' => 3000]),
            ['slug' => 'adabiyot', 'telegram_channel' => null, 'confidence' => 'low', 'evidence' => 'Nothing found.'],
            ['slug' => 'no-such-house', 'telegram_channel' => 'x_house'],
        ]));

        $this->artisan('publishers:import-suggestions', ['--file' => $this->file])
            ->expectsOutputToContain('missing  no-such-house')
            ->expectsOutputToContain('1 new and 0 refreshed suggestion(s) waiting for review; 0 already decided, 1 with nothing found, 1 not found.')
            ->assertSuccessful();

        $suggestion = PublisherSuggestion::query()->sole();
        $this->assertSame(ModerationStatus::Pending, $suggestion->status);
        $this->assertSame('munirnashr', $suggestion->telegram_channel);
        $this->assertSame('munir_admin', $suggestion->order_contact);
        $this->assertSame('munir.nashr', $suggestion->instagram);
        $this->assertNull($suggestion->website_url);
        $this->assertNull($suggestion->established_year);
        $this->assertSame('Religious books.', $suggestion->bio['en']);
        $this->assertStringStartsWith('publisher-suggestions/', $suggestion->logo_path);
        Storage::disk('public')->assertExists($suggestion->logo_path);

        // The page itself is untouched until an admin publishes.
        $munir->refresh();
        $this->assertNull($munir->telegram_channel);
        $this->assertNull($munir->logo_path);
        $this->assertNull($munir->getTranslation('bio', 'uz', false) ?: null);
    }

    public function test_loading_again_refreshes_what_waits_and_leaves_what_was_decided(): void
    {
        Publisher::create(['slug' => 'munir', 'name' => 'Munir']);
        $rejected = Publisher::create(['slug' => 'adabiyot', 'name' => 'Adabiyot']);
        PublisherSuggestion::create(['publisher_id' => $rejected->id, 'telegram_channel' => 'wrong_one', 'status' => ModerationStatus::Rejected]);

        $this->load([$this->munirEntry()]);
        $this->load([
            $this->munirEntry(['phone' => '+998 90 765 43 21']),
            ['slug' => 'adabiyot', 'telegram_channel' => 'adabiyotnashr'],
        ]);

        $this->assertSame('+998 90 765 43 21', PublisherSuggestion::query()->where('telegram_channel', 'munirnashr')->sole()->phone);
        $this->assertSame('wrong_one', $rejected->suggestion()->sole()->telegram_channel);
        $this->assertSame(2, PublisherSuggestion::query()->count());
    }

    public function test_the_review_starts_from_what_the_page_already_has(): void
    {
        $munir = Publisher::create(['slug' => 'munir', 'name' => 'Munir', 'phone' => '+998 71 000 00 00', 'logo_path' => 'publishers/own.png']);
        $munir->setTranslation('bio', 'uz', 'Oʻzimiz yozgan matn.')->save();
        $this->load([$this->munirEntry()]);

        $state = PublisherSuggestions::formState(PublisherSuggestion::query()->sole());

        $this->assertSame('+998 71 000 00 00', $state['phone']);
        $this->assertSame('Oʻzimiz yozgan matn.', $state['bio']['uz']);
        $this->assertSame('Религиозные книги.', $state['bio']['ru']);
        $this->assertSame('munirnashr', $state['telegram_channel']);
        // It has a logo of its own: the channel photo is offered, not chosen.
        $this->assertFalse($state['use_logo']);
    }

    public function test_an_admin_reviews_and_publishes(): void
    {
        $munir = Publisher::create(['slug' => 'munir', 'name' => 'Munir']);
        $this->load([$this->munirEntry()]);
        $suggestion = PublisherSuggestion::query()->sole();

        $this->actingAs($this->admin());

        Livewire::test(ListPublisherSuggestions::class)
            ->assertCanSeeTableRecords([$suggestion])
            ->mountTableAction('review', $suggestion)
            ->assertTableActionDataSet(['telegram_channel' => 'munirnashr', 'use_logo' => true])
            ->setTableActionData(['phone' => '+998 90 111 22 33', 'bio' => ['uz' => 'Tuzatilgan matn.', 'ru' => 'Религиозные книги.', 'en' => '']])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $munir->refresh();
        $this->assertSame('munirnashr', $munir->telegram_channel);
        $this->assertSame('munir_admin', $munir->order_contact);
        $this->assertSame('https://munir.uz', $munir->website_url);
        $this->assertSame('+998 90 111 22 33', $munir->phone);
        $this->assertSame('munir.nashr', $munir->instagram);
        $this->assertSame(2012, $munir->established_year);
        $this->assertSame('Tuzatilgan matn.', $munir->getTranslation('bio', 'uz'));
        $this->assertSame('', $munir->getTranslation('bio', 'en', false));
        $this->assertStringStartsWith('publishers/', $munir->logo_path);
        Storage::disk('public')->assertExists($munir->logo_path);

        $this->assertSame(ModerationStatus::Approved, $suggestion->refresh()->status);
        $this->assertNotNull($suggestion->reviewed_at);
    }

    public function test_a_bad_channel_name_is_not_published(): void
    {
        Publisher::create(['slug' => 'munir', 'name' => 'Munir']);
        $this->load([$this->munirEntry()]);

        $this->actingAs($this->admin());

        Livewire::test(ListPublisherSuggestions::class)
            ->callTableAction('review', PublisherSuggestion::query()->sole(), ['telegram_channel' => 'not a channel!'])
            ->assertHasTableActionErrors(['telegram_channel']);

        $this->assertSame(ModerationStatus::Pending, PublisherSuggestion::query()->sole()->status);
    }

    public function test_an_admin_rejects(): void
    {
        $munir = Publisher::create(['slug' => 'munir', 'name' => 'Munir']);
        $this->load([$this->munirEntry()]);

        $this->actingAs($this->admin());

        Livewire::test(ListPublisherSuggestions::class)
            ->callTableAction('reject', PublisherSuggestion::query()->sole());

        $this->assertSame(ModerationStatus::Rejected, PublisherSuggestion::query()->sole()->status);
        $this->assertNull($munir->refresh()->telegram_channel);
    }

    public function test_only_admins_see_the_suggestions(): void
    {
        $this->actingAs(User::create(['name' => 'P', 'email' => 'p@example.test', 'password' => 'password', 'role' => UserRole::Publisher]));

        $this->get('/admin/publisher-suggestions')->assertForbidden();
    }

    public function test_the_publisher_list_links_each_channel(): void
    {
        $munir = Publisher::create(['slug' => 'munir', 'name' => 'Munir', 'telegram_channel' => 'munirnashr']);

        $this->actingAs($this->admin());

        Livewire::test(ListPublishers::class)
            ->assertTableColumnStateSet('telegram_channel', 'munirnashr', $munir)
            ->assertSee('https://t.me/munirnashr');
    }

    public function test_the_shipped_file_is_a_list_of_publishers(): void
    {
        $entries = json_decode((string) file_get_contents(database_path('data/publisher-suggestions.json')), true);

        $this->assertIsArray($entries);

        foreach ($entries as $entry) {
            $this->assertNotEmpty($entry['slug'] ?? null);
            $this->assertContains($entry['confidence'] ?? null, ['high', 'medium', 'low', null], $entry['slug']);
        }
    }
}
