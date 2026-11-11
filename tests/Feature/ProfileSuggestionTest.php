<?php

namespace Tests\Feature;

use App\Enums\ModerationStatus;
use App\Enums\UserRole;
use App\Filament\Resources\ProfileSuggestionResource\Pages\ListProfileSuggestions;
use App\Models\Author;
use App\Models\ProfileSuggestion;
use App\Models\Translator;
use App\Models\User;
use App\Services\ProfileSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Researched facts about authors and translators: loaded for review,
 * reaching a page only when an admin publishes them.
 */
class ProfileSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'profiles');
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

    private function load(array $entries, array $options = []): \Illuminate\Testing\PendingCommand
    {
        file_put_contents($this->file, json_encode($entries));

        return $this->artisan('profiles:import-suggestions', ['--file' => $this->file, ...$options]);
    }

    private function kingEntry(array $overrides = []): array
    {
        return [
            'type' => 'author',
            'slug' => 'stiven-king',
            'bio' => ['uz' => 'Amerikalik yozuvchi.', 'ru' => 'Американский писатель.', 'en' => 'American writer.'],
            'birth_year' => 1947,
            'death_year' => null,
            'nationality' => ['uz' => 'AQSh', 'ru' => 'США', 'en' => 'United States'],
            'original_name' => 'Stephen King',
            'sources' => ['https://en.wikipedia.org/wiki/Stephen_King', 'javascript:alert(1)'],
            'confidence' => 'high',
            'note' => null,
            ...$overrides,
        ];
    }

    public function test_findings_wait_for_review_and_do_not_touch_the_page(): void
    {
        $king = Author::create(['slug' => 'stiven-king', 'name' => 'Stiven King']);
        Translator::create(['slug' => 'zarina-tojiyeva', 'name' => 'Zarina Tojiyeva']);
        Translator::create(['slug' => 'nobody', 'name' => 'Nobody']);

        $this->load([
            $this->kingEntry(['death_year' => 1900]),
            ['type' => 'translator', 'slug' => 'zarina-tojiyeva', 'bio' => ['uz' => 'Ingliz tilidan tarjimon.'], 'title' => ['uz' => 'Tarjimon'], 'confidence' => 'medium'],
            ['type' => 'translator', 'slug' => 'nobody', 'bio' => null, 'note' => 'Nothing proven.'],
            ['type' => 'author', 'slug' => 'no-such-author', 'bio' => ['uz' => 'X']],
            ['type' => 'publisher', 'slug' => 'stiven-king', 'bio' => ['uz' => 'X']],
        ])
            ->expectsOutputToContain('missing  author:no-such-author')
            ->expectsOutputToContain('2 new, 0 refreshed and 0 reopened suggestion(s) waiting for review; 0 already decided, 1 with nothing found, 2 not found.')
            ->assertSuccessful();

        $suggestion = ProfileSuggestion::query()->where('subject_type', Author::class)->sole();
        $this->assertTrue($suggestion->subject->is($king));
        // A death before the birth is not a fact: neither year is kept.
        $this->assertNull($suggestion->birth_year);
        $this->assertNull($suggestion->death_year);
        $this->assertSame('United States', $suggestion->nationality['en']);
        $this->assertSame(['https://en.wikipedia.org/wiki/Stephen_King'], $suggestion->sources);

        $king->refresh();
        $this->assertNull($king->getTranslation('bio', 'uz', false) ?: null);
        $this->assertNull($king->birth_year);
    }

    public function test_an_admin_reviews_and_publishes_an_author(): void
    {
        $king = Author::create(['slug' => 'stiven-king', 'name' => 'Stiven King']);
        $this->load([$this->kingEntry()])->assertSuccessful();
        $suggestion = ProfileSuggestion::query()->sole();

        $this->actingAs($this->admin());

        Livewire::test(ListProfileSuggestions::class)
            ->assertCanSeeTableRecords([$suggestion])
            ->mountTableAction('review', $suggestion)
            ->assertTableActionDataSet(['birth_year' => 1947, 'original_name' => 'Stephen King'])
            ->setTableActionData(['bio' => ['uz' => 'Tuzatilgan.', 'ru' => 'Американский писатель.', 'en' => '']])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $king->refresh();
        $this->assertSame(1947, $king->birth_year);
        $this->assertNull($king->death_year);
        $this->assertSame('Stephen King', $king->original_name);
        $this->assertSame('AQSh', $king->getTranslation('nationality', 'uz'));
        $this->assertSame('Tuzatilgan.', $king->getTranslation('bio', 'uz'));
        $this->assertSame('', $king->getTranslation('bio', 'en', false));
        $this->assertSame(ModerationStatus::Approved, $suggestion->refresh()->status);

        $this->getJson('/api/authors/stiven-king?locale=en')
            ->assertOk()
            ->assertJsonPath('data.birthYear', 1947)
            ->assertJsonPath('data.nationality', 'United States');
    }

    public function test_a_translator_page_keeps_what_it_has_unless_the_admin_takes_the_finding(): void
    {
        $user = User::create(['name' => 'Zarina', 'email' => 'zarina@example.test', 'password' => 'password', 'role' => UserRole::Translator]);
        $zarina = Translator::create(['user_id' => $user->id, 'slug' => 'zarina-tojiyeva', 'name' => 'Zarina Tojiyeva']);
        $zarina->setTranslation('bio', 'uz', 'Oʻzim yozgan matn.')->save();

        $this->load([[
            'type' => 'translator', 'slug' => 'zarina-tojiyeva',
            'bio' => ['uz' => 'Ingliz tilidan tarjimon.', 'en' => 'Translator from English.'],
            'title' => ['uz' => 'Tarjimon', 'en' => 'Translator'],
            'location' => ['uz' => 'Toshkent'],
        ]])->assertSuccessful();

        $suggestion = ProfileSuggestion::query()->sole();
        $state = ProfileSuggestions::formState($suggestion);

        $this->assertSame('Oʻzim yozgan matn.', $state['bio']['uz']);
        $this->assertSame('Translator from English.', $state['bio']['en']);
        $this->assertSame('Tarjimon', $state['title']['uz']);
        $this->assertArrayNotHasKey('birth_year', $state);

        app(ProfileSuggestions::class)->publish($suggestion, $state);

        $zarina->refresh();
        $this->assertSame('Oʻzim yozgan matn.', $zarina->getTranslation('bio', 'uz'));
        $this->assertSame('Translator from English.', $zarina->getTranslation('bio', 'en'));
        $this->assertSame('Toshkent', $zarina->getTranslation('location', 'uz'));
    }

    public function test_a_new_bio_reopens_a_decided_person_only_when_asked(): void
    {
        Author::create(['slug' => 'stiven-king', 'name' => 'Stiven King']);
        $this->load([$this->kingEntry()])->assertSuccessful();
        $suggestion = ProfileSuggestion::query()->sole();
        app(ProfileSuggestions::class)->reject($suggestion);

        $richer = $this->kingEntry(['bio' => ['uz' => 'Amerikalik yozuvchi, «Yashil mil» muallifi.']]);

        $this->load([$richer])->expectsOutputToContain('1 already decided')->assertSuccessful();
        $this->load([$richer], ['--reopen' => true])->expectsOutputToContain('1 reopened')->assertSuccessful();

        $this->assertSame(ModerationStatus::Pending, $suggestion->refresh()->status);
        $this->assertSame('Amerikalik yozuvchi, «Yashil mil» muallifi.', $suggestion->bio['uz']);
    }

    public function test_only_admins_see_the_suggestions(): void
    {
        $user = User::create(['name' => 'T', 'email' => 't@example.test', 'password' => 'password', 'role' => UserRole::Translator]);

        $this->actingAs($user)->get('/admin/profile-suggestions')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/profile-suggestions')->assertOk();
    }

    public function test_the_shipped_file_is_a_list_of_people(): void
    {
        $entries = json_decode((string) file_get_contents(database_path('data/profile-suggestions.json')), true);

        $this->assertIsArray($entries);
        $this->assertTrue(array_is_list($entries));

        foreach ($entries as $entry) {
            $this->assertContains($entry['type'] ?? null, ['author', 'translator']);
            $this->assertNotEmpty($entry['slug'] ?? null);
        }
    }
}
