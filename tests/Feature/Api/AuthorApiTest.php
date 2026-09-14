<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Author;
use App\Models\Book;
use App\Models\Translator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /api/authors is one half of the homepage's "Authors" section (the other
 * half is the bookCount each row shows). The Next.js app reads it through
 * lib/api.ts and types it as `Author` in lib/types.ts, so the field names
 * asserted here are a contract: rename one on this side and the section
 * silently renders blanks rather than failing.
 */
class AuthorApiTest extends TestCase
{
    use RefreshDatabase;

    private function author(array $attributes = []): Author
    {
        return Author::create(array_merge([
            'slug' => 'ernest-hemingway',
            'name' => 'Ernest Hemingway',
        ], $attributes));
    }

    private function translator(string $slug = 'ilhom'): Translator
    {
        $user = User::create([
            'name' => 'Ilhom',
            'email' => "{$slug}@example.test",
            'password' => 'password',
            'role' => UserRole::Translator,
        ]);

        return Translator::create([
            'user_id' => $user->id,
            'slug' => $slug,
            'name' => 'Ilhom',
            'years_active' => 5,
        ]);
    }

    private function book(Translator $translator, Author $author, array $attributes = []): Book
    {
        return Book::create(array_merge([
            'translator_id' => $translator->id,
            'author_id' => $author->id,
            'uzbek_title' => 'Chol va dengiz',
            'original_title' => 'The Old Man and the Sea',
            'author' => $author->name,
            'source_language' => 'en',
        ], $attributes));
    }

    public function test_the_collection_matches_the_shape_the_site_expects(): void
    {
        $this->author([
            'bio' => ['en' => 'American novelist.'],
            'nationality' => ['en' => 'American'],
            'birth_year' => 1899,
            'death_year' => 1961,
            'website_url' => 'https://example.test/hemingway',
            'portrait_path' => 'authors/hemingway.jpg',
        ]);

        $this->getJson('/api/authors?locale=en')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'ernest-hemingway')
            ->assertJsonPath('data.0.name', 'Ernest Hemingway')
            ->assertJsonPath('data.0.bio', 'American novelist.')
            ->assertJsonPath('data.0.nationality', 'American')
            ->assertJsonPath('data.0.birthYear', 1899)
            ->assertJsonPath('data.0.deathYear', 1961)
            ->assertJsonPath('data.0.websiteUrl', 'https://example.test/hemingway')
            // Relative on purpose: the API cannot know which host name the
            // caller used to reach it, so lib/api.ts resolves it.
            ->assertJsonPath('data.0.portraitUrl', '/storage/authors/hemingway.jpg')
            ->assertJsonPath('data.0.bookCount', 0);
    }

    public function test_an_id_is_serialised_as_a_string(): void
    {
        $author = $this->author();

        // lib/types.ts declares `id: string`. Sending a JSON number would
        // make every === comparison on the site fail without a type error.
        $this->getJson('/api/authors')
            ->assertJsonPath('data.0.id', (string) $author->id);
    }

    public function test_a_missing_portrait_is_null_rather_than_an_empty_path(): void
    {
        $this->author();

        // "/storage/" on its own would be a live URL pointing at a
        // directory listing, which is what a bare string concat produces.
        $this->getJson('/api/authors')
            ->assertJsonPath('data.0.portraitUrl', null);
    }

    public function test_book_counts_come_from_the_author_relation(): void
    {
        $author = $this->author();
        $other = $this->author(['slug' => 'orhan-pamuk', 'name' => 'Orhan Pamuk']);
        $translator = $this->translator();

        $this->book($translator, $author);
        $this->book($translator, $author, ['uzbek_title' => 'Alvido, qurol']);
        $this->book($translator, $other, ['uzbek_title' => 'Qor']);

        $response = $this->getJson('/api/authors')->assertOk();

        $counts = collect($response->json('data'))->pluck('bookCount', 'slug');

        $this->assertSame(2, $counts['ernest-hemingway']);
        $this->assertSame(1, $counts['orhan-pamuk']);
    }

    public function test_the_collection_is_ordered_by_name(): void
    {
        $this->author(['slug' => 'orhan-pamuk', 'name' => 'Orhan Pamuk']);
        $this->author();
        $this->author(['slug' => 'franz-kafka', 'name' => 'Franz Kafka']);

        $this->getJson('/api/authors')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ernest Hemingway')
            ->assertJsonPath('data.1.name', 'Franz Kafka')
            ->assertJsonPath('data.2.name', 'Orhan Pamuk');
    }

    public function test_translatable_fields_follow_the_requested_locale(): void
    {
        $this->author([
            'bio' => ['uz' => 'Amerikalik yozuvchi.', 'ru' => 'Американский писатель.'],
            'nationality' => ['uz' => 'Amerikalik', 'ru' => 'Американец'],
        ]);

        $this->getJson('/api/authors?locale=ru')
            ->assertJsonPath('data.0.bio', 'Американский писатель.')
            ->assertJsonPath('data.0.nationality', 'Американец');

        $this->getJson('/api/authors?locale=uz')
            ->assertJsonPath('data.0.bio', 'Amerikalik yozuvchi.');
    }

    public function test_a_locale_with_no_translation_falls_back_rather_than_blanking(): void
    {
        $this->author(['bio' => ['uz' => 'Amerikalik yozuvchi.']]);

        // AppServiceProvider sets fallbackLocale: 'uz'. A blank biography
        // would look like missing data rather than an untranslated one.
        $this->getJson('/api/authors?locale=en')
            ->assertJsonPath('data.0.bio', 'Amerikalik yozuvchi.');
    }

    public function test_an_unsupported_locale_is_ignored(): void
    {
        $this->author(['bio' => ['uz' => 'Amerikalik yozuvchi.', 'en' => 'American novelist.']]);

        // Anything outside uz/ru/en leaves the app locale alone instead of
        // handing an arbitrary query string to setLocale() — so the answer
        // is whatever the request with no locale at all returns.
        $default = $this->getJson('/api/authors')->assertOk()->json('data.0.bio');

        $this->getJson('/api/authors?locale=de')
            ->assertOk()
            ->assertJsonPath('data.0.bio', $default);
    }

    public function test_a_single_author_can_be_fetched(): void
    {
        $author = $this->author();
        $this->book($this->translator(), $author);

        $this->getJson("/api/authors/{$author->id}")
            ->assertOk()
            ->assertJsonPath('data.slug', 'ernest-hemingway')
            ->assertJsonPath('data.bookCount', 1);
    }

    public function test_an_unknown_author_is_a_404(): void
    {
        // lib/api.ts turns a 404 into null (a genuinely missing record) and
        // throws on anything else, so this status is load-bearing.
        $this->getJson('/api/authors/404404')->assertNotFound();
    }

    public function test_the_endpoints_need_no_authentication(): void
    {
        $this->author();

        $this->getJson('/api/authors')->assertOk();
    }
}
