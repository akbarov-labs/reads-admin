<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\AuthorResource\Pages\ListAuthors;
use App\Models\Author;
use App\Models\AuthorAlias;
use App\Models\User;
use App\Services\AuthorMerger;
use App\Services\CatalogueRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * One person entered twice ("Toxir Malik" beside "Tohir Malik") becomes one
 * author, and stays one: imports and the bot find the merged spelling, and
 * the old page link still arrives.
 */
class AuthorMergeTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    public function test_the_books_and_blank_details_move_and_the_duplicate_is_gone(): void
    {
        $kept = $this->author('Tohir Malik');
        $kept->update(['bio' => ['uz' => 'Yozuvchi']]);
        $duplicate = $this->author('Toxir Malik');
        $duplicate->update([
            'original_name' => 'Тоҳир Малик',
            'birth_year' => 1946,
            'bio' => ['uz' => 'Boshqa matn', 'ru' => 'Писатель'],
        ]);

        $own = $this->catalogueBook(['uzbek_title' => 'Shaytanat'], ['isbn' => '9789943000001'], authors: [$kept]);
        $moved = $this->catalogueBook(['uzbek_title' => 'Alvido bolalik'], ['isbn' => '9789943000002'], authors: [$this->author('Muharrir'), $duplicate]);
        $both = $this->catalogueBook(['uzbek_title' => 'Murdalar'], ['isbn' => '9789943000003'], authors: [$kept, $duplicate]);

        app(AuthorMerger::class)->merge($duplicate, $kept);

        $this->assertNull(Author::find($duplicate->id));
        $kept->refresh();

        $this->assertSame([$own->id, $moved->id, $both->id], $kept->books()->orderBy('books.id')->pluck('books.id')->all());
        $this->assertSame(1, $moved->authors()->where('authors.id', $kept->id)->sole()->pivot->position, 'it keeps its place in the credits');
        $this->assertSame(1, $both->authors()->count(), 'a book credited to both is credited once');

        // Only blanks are filled.
        $this->assertSame('Yozuvchi', $kept->getTranslation('bio', 'uz'));
        $this->assertSame('Писатель', $kept->getTranslation('bio', 'ru'));
        $this->assertSame(1946, $kept->birth_year);
        $this->assertSame('Тоҳир Малик', $kept->original_name);

        $this->assertSame(['Toxir Malik'], $kept->aliases()->pluck('name')->all());
        $this->assertSame('toxir-malik', $kept->aliases()->sole()->slug);
    }

    public function test_the_old_link_finds_the_kept_author(): void
    {
        $kept = $this->author('Tohir Malik');
        app(AuthorMerger::class)->merge($this->author('Toxir Malik'), $kept);

        $this->getJson('/api/authors/toxir-malik')
            ->assertOk()
            ->assertJsonPath('data.slug', 'tohir-malik');
    }

    public function test_an_import_or_the_bot_spelling_it_the_old_way_does_not_make_it_again(): void
    {
        $kept = $this->author('Tohir Malik');
        app(AuthorMerger::class)->merge($this->author('Toxir Malik'), $kept);

        $records = app(CatalogueRecords::class);

        $this->assertSame($kept->id, $records->author('Toxir Malik')->id);
        $this->assertSame($kept->id, $records->author('TOXIR  malik')->id, 'any case or spacing of it');
        $this->assertSame(1, Author::count());

        // Nor does a new author take the old slug.
        $this->assertSame('toxir-malik-2', Author::generateUniqueSlug('Toxir Malik'));
    }

    public function test_merging_again_carries_the_earlier_aliases_along(): void
    {
        $first = $this->author('Tohir Malik');
        $second = $this->author('Toxir Malik');
        $third = $this->author('Tohir Malik (yozuvchi)');

        app(AuthorMerger::class)->merge($second, $third);
        app(AuthorMerger::class)->merge($third, $first);

        $this->assertEqualsCanonicalizing(['Toxir Malik', 'Tohir Malik (yozuvchi)'], $first->aliases()->pluck('name')->all());
        $this->getJson('/api/authors/toxir-malik')->assertJsonPath('data.slug', 'tohir-malik');
    }

    public function test_an_author_is_not_merged_into_itself(): void
    {
        $author = $this->author('Tohir Malik');

        $this->expectException(\InvalidArgumentException::class);
        app(AuthorMerger::class)->merge($author, $author);
    }

    public function test_an_admin_merges_from_the_author_list(): void
    {
        $kept = $this->author('Tohir Malik');
        $duplicate = $this->author('Toxir Malik');
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => UserRole::Admin]));

        Livewire::test(ListAuthors::class)
            ->assertTableActionVisible('merge', $duplicate)
            ->callTableAction('merge', $duplicate, data: ['into' => $kept->id])
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertNull(Author::find($duplicate->id));
        $this->assertSame(1, AuthorAlias::where('author_id', $kept->id)->count());
    }

    public function test_the_command_lists_spellings_that_look_like_one_person(): void
    {
        $this->author('Tohir Malik');
        $this->author('Toxir Malik');
        $this->author('Jorj Oruell');
        $this->author('Jorj Oruel');
        $this->author('Abdulla Qodiriy');

        Artisan::call('authors:duplicates');
        $output = Artisan::output();

        $this->assertStringContainsString('Tohir Malik | Toxir Malik', $output);
        $this->assertStringContainsString('Jorj Oruell | Jorj Oruel', $output);
        $this->assertStringNotContainsString('Qodiriy', $output);
        $this->assertStringContainsString('2 groups', $output);
    }
}
