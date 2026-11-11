<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\AuthorAlias;
use App\Models\ProfileSuggestion;
use App\Models\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * catalogue:apply-fixes: a reviewed list of corrections, shown first and
 * made only with --apply, each one checked against the catalogue as it is.
 */
class ApplyCatalogueFixesTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'fixes');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    private function person(string $slug, string $name, array $attributes = []): Author
    {
        return Author::create(['slug' => $slug, 'name' => $name, ...$attributes]);
    }

    private function fix(array $fixes, bool $apply): \Illuminate\Testing\PendingCommand
    {
        file_put_contents($this->file, json_encode($fixes));

        return $this->artisan('catalogue:apply-fixes', ['--file' => $this->file, ...($apply ? ['--apply' => true] : [])]);
    }

    public function test_a_dry_run_shows_the_changes_and_makes_none(): void
    {
        $hawking = $this->person('stephen-hawking', 'Stephen Hawking');
        $king = $this->person('stiven-king', 'Stiven King');
        $book = $this->catalogueBook(['uzbek_title' => 'Стивен Кинг: Кристина'], authors: [$hawking]);

        $this->fix(['move_books' => [['book' => $book->id, 'from' => 'stephen-hawking', 'to' => 'stiven-king']]], apply: false)
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('move     «Стивен Кинг: Кристина»: Stephen Hawking → Stiven King')
            ->expectsOutputToContain('1 change(s) to make; 0 skipped.')
            ->assertSuccessful();

        $this->assertSame([$hawking->id], $book->authors()->pluck('authors.id')->all());

        $this->fix(['move_books' => [['book' => $book->id, 'from' => 'stephen-hawking', 'to' => 'stiven-king']]], apply: true)
            ->expectsOutputToContain('1 change(s) made')
            ->assertSuccessful();

        $this->assertSame([$king->id], $book->authors()->pluck('authors.id')->all());
    }

    public function test_a_book_moves_to_a_new_author_and_one_credited_otherwise_is_left(): void
    {
        $christie = $this->person('agata-kristi', 'Agata Kristi');
        $other = $this->person('someone', 'Someone');
        $wrong = $this->catalogueBook(['uzbek_title' => 'Liz Tomforde: Caught Up'], authors: [$christie]);
        $changed = $this->catalogueBook(['uzbek_title' => 'Boshqa'], authors: [$other]);

        $this->fix(['move_books' => [
            ['book' => $wrong->id, 'from' => 'agata-kristi', 'to_name' => 'Liz Tomforde'],
            ['book' => $changed->id, 'from' => 'agata-kristi', 'to_name' => 'Liz Tomforde'],
        ]], apply: true)
            ->expectsOutputToContain('«Boshqa» is no longer credited to Agata Kristi')
            ->expectsOutputToContain('1 change(s) made; 1 skipped.')
            ->assertSuccessful();

        $this->assertSame(['Liz Tomforde'], $wrong->authors()->pluck('name')->all());
        $this->assertSame(['Someone'], $changed->authors()->pluck('name')->all());
    }

    public function test_the_real_author_goes_first_and_the_old_credit_stays(): void
    {
        $qambarov = $this->person('azamxon-qambarov', "A'zamxon Qambarov");
        $marginoniy = $this->person('burhonuddin-marginoniy', "Burhonuddin Marg'inoniy");
        $book = $this->catalogueBook(['uzbek_title' => 'Hidoya. Namoz kitobi'], authors: [$qambarov]);

        $this->fix(['add_first_author' => [['author' => 'burhonuddin-marginoniy', 'books' => [$book->id]]]], apply: true)->assertSuccessful();
        // Again: already credited, nothing doubles.
        $this->fix(['add_first_author' => [['author' => 'burhonuddin-marginoniy', 'books' => [$book->id]]]], apply: true)
            ->expectsOutputToContain('already credits')
            ->assertSuccessful();

        $this->assertSame([$marginoniy->id, $qambarov->id], $book->authors()->pluck('authors.id')->all());
    }

    public function test_spellings_of_one_person_become_one_author_with_its_research(): void
    {
        $kept = $this->person('lev-nikolayevich-tolstoy', 'Lev Nikolayevich Tolstoy');
        $cyrillic = $this->person('lev-tolstoi', 'Лев Толстой');
        $initials = $this->person('lntolstoy', 'L.N.Tolstoy');
        $war = $this->catalogueBook(['uzbek_title' => 'Война и мир'], authors: [$cyrillic]);
        $anna = $this->catalogueBook(['uzbek_title' => 'Anna Karenina'], authors: [$initials]);
        // Only the Cyrillic record was researched: its findings follow the person.
        $suggestion = ProfileSuggestion::create(['subject_type' => Author::class, 'subject_id' => $cyrillic->id, 'bio' => ['uz' => 'Rus yozuvchisi.']]);

        $this->fix(['merge_authors' => [[
            'into' => 'lev-nikolayevich-tolstoy',
            'from' => ['lev-tolstoi', 'lntolstoy', 'no-such-tolstoy'],
            'name' => 'Lev Tolstoy',
            'original_name' => 'Лев Толстой',
        ]]], apply: true)
            ->expectsOutputToContain('no-such-tolstoy: not found')
            ->assertSuccessful();

        $kept->refresh();
        $this->assertSame('Lev Tolstoy', $kept->name);
        $this->assertSame('Лев Толстой', $kept->original_name);
        $this->assertSame(1, Author::query()->where('name', 'like', '%olstoy%')->orWhere('name', 'like', '%Толстой%')->count());
        $this->assertSame([$kept->id], $war->authors()->pluck('authors.id')->all());
        $this->assertSame([$kept->id], $anna->authors()->pluck('authors.id')->all());
        $this->assertSame($kept->id, $suggestion->refresh()->subject_id);
        // Old links and spellings still lead here.
        $this->assertTrue(AuthorAlias::query()->where('author_id', $kept->id)->where('slug', 'lev-tolstoi')->exists());
        $this->assertTrue(AuthorAlias::query()->where('author_id', $kept->id)->where('name', 'Lev Nikolayevich Tolstoy')->exists());
        $this->getJson('/api/authors/lev-tolstoi')->assertOk()->assertJsonPath('data.slug', 'lev-nikolayevich-tolstoy');
    }

    public function test_a_duplicate_research_row_goes_when_the_kept_author_has_its_own(): void
    {
        $kept = $this->person('charlz-dikkens', 'Charlz Dikkens');
        $duplicate = $this->person('charles-dickens', 'Charles Dickens');
        ProfileSuggestion::create(['subject_type' => Author::class, 'subject_id' => $kept->id, 'bio' => ['uz' => 'A']]);
        ProfileSuggestion::create(['subject_type' => Author::class, 'subject_id' => $duplicate->id, 'bio' => ['uz' => 'B']]);

        $this->fix(['merge_authors' => [['into' => 'charlz-dikkens', 'from' => ['charles-dickens']]]], apply: true)->assertSuccessful();

        $this->assertSame([$kept->id], ProfileSuggestion::query()->pluck('subject_id')->all());
    }

    public function test_a_translator_name_in_the_wrong_case_is_corrected(): void
    {
        Translator::create(['slug' => 'igoria-egorova', 'name' => 'Игоря Егорова']);

        $this->fix(['rename_translators' => [['slug' => 'igoria-egorova', 'name' => 'Игорь Егоров']]], apply: true)->assertSuccessful();

        $this->assertSame('Игорь Егоров', Translator::query()->where('slug', 'igoria-egorova')->value('name'));
    }

    public function test_the_shipped_list_reads(): void
    {
        $this->artisan('catalogue:apply-fixes')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();
    }
}
