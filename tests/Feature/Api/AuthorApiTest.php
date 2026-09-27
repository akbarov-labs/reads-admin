<?php

namespace Tests\Feature\Api;

use App\Enums\ModerationStatus;
use App\Models\Author;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalogue;
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
    use BuildsCatalogue;
    use RefreshDatabase;

    /** An author with one published book, unless told otherwise — the list only shows authors with books. */
    private function author(array $attributes = [], int $books = 1): Author
    {
        $author = Author::create(array_merge([
            'slug' => 'ernest-hemingway',
            'name' => 'Ernest Hemingway',
        ], $attributes));

        foreach (range(1, $books) as $i) {
            if ($books > 0) {
                $this->catalogueBook(['uzbek_title' => "{$author->name} {$i}"], authors: [$author]);
            }
        }

        return $author;
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
            ->assertJsonPath('data.0.bookCount', 1);
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
        $this->author(books: 2);
        $this->author(['slug' => 'orhan-pamuk', 'name' => 'Orhan Pamuk']);

        $counts = collect($this->getJson('/api/authors')->assertOk()->json('data'))->pluck('bookCount', 'slug');

        $this->assertSame(2, $counts['ernest-hemingway']);
        $this->assertSame(1, $counts['orhan-pamuk']);
    }

    public function test_each_author_carries_a_few_covers_for_their_card(): void
    {
        $author = $this->author(books: 0);
        foreach (range(1, 5) as $year) {
            $this->catalogueBook(['uzbek_title' => "Kitob {$year}"], ['year' => 2000 + $year, 'cover_path' => "covers/{$year}.webp"], [$author]);
        }

        $this->getJson('/api/authors')
            ->assertJsonCount(4, 'data.0.coverUrls')
            ->assertJsonPath('data.0.coverUrls.0', '/storage/covers/5.webp');
    }

    public function test_only_authors_with_a_published_book_are_listed(): void
    {
        $this->author(['slug' => 'no-books', 'name' => 'No Books'], books: 0);
        $pending = $this->author(['slug' => 'pending', 'name' => 'Pending'], books: 0);
        $this->catalogueBook(['status' => ModerationStatus::Pending], authors: [$pending]);

        $this->getJson('/api/authors')->assertJsonCount(0, 'data');
    }

    public function test_the_collection_puts_the_most_published_first_then_sorts_by_name(): void
    {
        $this->author(['slug' => 'orhan-pamuk', 'name' => 'Orhan Pamuk']);
        $this->author();
        $this->author(['slug' => 'franz-kafka', 'name' => 'Franz Kafka'], books: 2);

        $this->getJson('/api/authors')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Franz Kafka')
            ->assertJsonPath('data.1.name', 'Ernest Hemingway')
            ->assertJsonPath('data.2.name', 'Orhan Pamuk');

        $this->getJson('/api/authors?sort=name')->assertJsonPath('data.1.name', 'Franz Kafka');
    }

    public function test_it_is_paginated_and_searchable(): void
    {
        $this->author(['slug' => 'orhan-pamuk', 'name' => 'Orhan Pamuk', 'original_name' => 'Orhan Pamuk']);
        $this->author(['slug' => 'abdulla-qodiriy', 'name' => 'Abdulla Qodiriy', 'original_name' => 'Абдулла Қодирий']);

        $this->getJson('/api/authors?per_page=1')->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/authors?q=Қодирий')->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'abdulla-qodiriy');
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
