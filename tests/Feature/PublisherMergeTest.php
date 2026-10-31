<?php

namespace Tests\Feature;

use App\Enums\ModerationStatus;
use App\Enums\UserRole;
use App\Filament\Resources\PublisherResource\Pages\ListPublishers;
use App\Models\Announcement;
use App\Models\Edition;
use App\Models\Publisher;
use App\Models\PublisherAlias;
use App\Models\User;
use App\Services\CatalogueRecords;
use App\Services\PublisherMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * One publisher entered twice ("Hilolnashr" beside "Hilol-nashr") becomes
 * one, and stays one: imports and the bot find the merged spelling, and the
 * old page link still arrives.
 */
class PublisherMergeTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    public function test_the_editions_announcements_and_blank_details_move_and_the_duplicate_is_gone(): void
    {
        $kept = $this->publisher('Hilol-nashr');
        $kept->update(['bio' => ['uz' => 'Nashriyot']]);
        $duplicate = $this->publisher('Hilolnashr');
        $owner = User::create(['name' => 'Hilol', 'email' => 'hilol@example.test', 'password' => 'password', 'role' => UserRole::Publisher]);
        $duplicate->update([
            'user_id' => $owner->id,
            'established_year' => 2005,
            'telegram_channel' => 'hilolnashr',
            'bio' => ['uz' => 'Boshqa matn', 'ru' => 'Издательство'],
        ]);

        $own = $this->catalogueBook(['uzbek_title' => 'Saodat asri'], ['isbn' => '9789943000001', 'publisher_id' => $kept->id]);
        $moved = $this->catalogueBook(['uzbek_title' => 'Ruhiy tarbiya'], ['isbn' => '9789943000002', 'publisher_id' => $duplicate->id]);
        Edition::whereKey($moved->editions()->sole()->id)->update(['updated_at' => '2026-01-01 10:00:00']);
        $announcement = Announcement::create([
            'publisher_id' => $duplicate->id,
            'source_url' => 'https://t.me/hilolnashr/1',
            'title' => 'Yangi kitob',
            'status' => ModerationStatus::Approved,
            'posted_at' => now(),
        ]);

        app(PublisherMerger::class)->merge($duplicate, $kept);

        $this->assertNull(Publisher::find($duplicate->id));
        $kept->refresh();

        $this->assertSame([$own->id, $moved->id], $kept->books()->orderBy('books.id')->pluck('books.id')->all());
        $this->assertNotSame('2026-01-01 10:00:00', (string) $moved->editions()->sole()->updated_at, 'the bot hears of the new publisher');
        $this->assertSame($kept->id, $announcement->fresh()->publisher_id);

        // Only blanks are filled, the account included.
        $this->assertSame('Nashriyot', $kept->getTranslation('bio', 'uz'));
        $this->assertSame('Издательство', $kept->getTranslation('bio', 'ru'));
        $this->assertSame(2005, $kept->established_year);
        $this->assertSame('hilolnashr', $kept->telegram_channel);
        $this->assertSame($owner->id, $kept->user_id);

        $this->assertSame('Hilolnashr', $kept->aliases()->sole()->name);
        $this->assertSame('hilolnashr', $kept->aliases()->sole()->slug);
    }

    public function test_the_old_link_finds_the_kept_publisher(): void
    {
        $kept = $this->publisher('Hilol-nashr');
        app(PublisherMerger::class)->merge($this->publisher('Hilolnashr'), $kept);

        $this->getJson('/api/publishers/hilolnashr')
            ->assertOk()
            ->assertJsonPath('data.slug', 'hilol-nashr');
    }

    public function test_an_import_or_the_bot_spelling_it_the_old_way_does_not_make_it_again(): void
    {
        $kept = $this->publisher('Zamin Nashr');
        app(PublisherMerger::class)->merge($this->publisher('Zamin kitoblari'), $kept);

        $records = app(CatalogueRecords::class);

        $this->assertSame($kept->id, $records->publisher('Zamin kitoblari')->id, 'a spelling the key alone does not catch');
        $this->assertSame($kept->id, $records->publisher('Zaminnashr')->id, 'nashr glued on');
        $this->assertSame(1, Publisher::count());

        // Nor does a new publisher take the old slug.
        $this->assertSame('zamin-kitoblari-2', Publisher::generateUniqueSlug('Zamin kitoblari'));
    }

    public function test_a_publisher_is_not_merged_into_itself(): void
    {
        $publisher = $this->publisher('Hilol-nashr');

        $this->expectException(\InvalidArgumentException::class);
        app(PublisherMerger::class)->merge($publisher, $publisher);
    }

    public function test_an_admin_merges_from_the_publisher_list(): void
    {
        $kept = $this->publisher('Hilol-nashr');
        $duplicate = $this->publisher('Hilolnashr');
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => UserRole::Admin]));

        Livewire::test(ListPublishers::class)
            ->assertTableActionVisible('merge', $duplicate)
            ->callTableAction('merge', $duplicate, data: ['into' => $kept->id])
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertNull(Publisher::find($duplicate->id));
        $this->assertSame(1, PublisherAlias::where('publisher_id', $kept->id)->count());
    }

    public function test_the_command_lists_spellings_that_look_like_one_publisher(): void
    {
        $this->publisher('Hilol-nashr');
        $this->publisher('Hilolnashr');
        $this->publisher('Factor Books');
        $this->publisher('Factorbooks');
        $this->publisher('Sharq');

        Artisan::call('publishers:duplicates');
        $output = Artisan::output();

        $this->assertStringContainsString('Hilol-nashr | Hilolnashr', $output);
        $this->assertStringContainsString('Factor Books | Factorbooks', $output);
        $this->assertStringNotContainsString('Sharq', $output);
        $this->assertStringContainsString('2 groups', $output);
    }

    public function test_the_migration_merges_the_pairs_it_finds_and_leaves_the_rest(): void
    {
        $kept = $this->publisher('Hilol-nashr');
        $duplicate = $this->publisher('Hilolnashr');
        $this->catalogueBook(['uzbek_title' => 'Ruhiy tarbiya'], ['isbn' => '9789943000002', 'publisher_id' => $duplicate->id]);
        $alone = Publisher::create(['name' => 'Factorbooks', 'slug' => 'factorbooks']);

        $migration = require database_path('migrations/2026_10_25_000002_merge_duplicate_publishers.php');
        $migration->up();
        $migration->up();

        $this->assertNull(Publisher::find($duplicate->id));
        $this->assertSame(1, $kept->books()->count());
        $this->assertNotNull(Publisher::find($alone->id), 'its pair is not here');
    }
}
