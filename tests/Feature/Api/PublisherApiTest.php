<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Book;
use App\Models\Publisher;
use App\Models\Translator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /api/publishers backs the homepage's "Publishers" section. Unlike the
 * author list it renders a logo, so logoUrl gets the same scrutiny here that
 * portraitUrl gets in AuthorApiTest: a bare "/storage/" would send
 * next/image off to fetch a directory listing.
 *
 * @see \Tests\Feature\Api\AuthorApiTest for the shared reasoning about
 *      locales, string ids and field names.
 */
class PublisherApiTest extends TestCase
{
    use RefreshDatabase;

    private function publisher(array $attributes = []): Publisher
    {
        return Publisher::create(array_merge([
            'slug' => 'yangi-asr-avlodi',
            'name' => 'Yangi asr avlodi',
        ], $attributes));
    }

    private function translator(): Translator
    {
        $user = User::create([
            'name' => 'Ilhom',
            'email' => 'ilhom@example.test',
            'password' => 'password',
            'role' => UserRole::Translator,
        ]);

        return Translator::create([
            'user_id' => $user->id,
            'slug' => 'ilhom',
            'name' => 'Ilhom',
            'years_active' => 5,
        ]);
    }

    private function book(Translator $translator, Publisher $publisher, string $title): Book
    {
        return Book::create([
            'translator_id' => $translator->id,
            'publisher_id' => $publisher->id,
            'uzbek_title' => $title,
            'original_title' => $title,
            'author' => 'Ernest Hemingway',
            'source_language' => 'en',
        ]);
    }

    public function test_the_collection_matches_the_shape_the_site_expects(): void
    {
        $this->publisher([
            'bio' => ['en' => 'Tashkent publishing house.'],
            'country' => ['en' => 'Uzbekistan'],
            'established_year' => 1991,
            'website_url' => 'https://example.test/yaa',
            'logo_path' => 'publishers/yaa.svg',
        ]);

        $this->getJson('/api/publishers?locale=en')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'yangi-asr-avlodi')
            ->assertJsonPath('data.0.name', 'Yangi asr avlodi')
            ->assertJsonPath('data.0.bio', 'Tashkent publishing house.')
            ->assertJsonPath('data.0.country', 'Uzbekistan')
            ->assertJsonPath('data.0.establishedYear', 1991)
            ->assertJsonPath('data.0.websiteUrl', 'https://example.test/yaa')
            ->assertJsonPath('data.0.logoUrl', '/storage/publishers/yaa.svg')
            ->assertJsonPath('data.0.bookCount', 0);
    }

    public function test_an_id_is_serialised_as_a_string(): void
    {
        $publisher = $this->publisher();

        // PublisherGrid keys its rows on publisher.id, so this is what
        // React's reconciler sees.
        $this->getJson('/api/publishers')
            ->assertJsonPath('data.0.id', (string) $publisher->id);
    }

    public function test_a_missing_logo_is_null_rather_than_an_empty_path(): void
    {
        $this->publisher();

        // PublisherGrid branches on `publisher.logoUrl ? <Image/> : initial`
        // — an empty-but-truthy string renders a broken image instead.
        $this->getJson('/api/publishers')
            ->assertJsonPath('data.0.logoUrl', null);
    }

    public function test_book_counts_come_from_the_publisher_relation(): void
    {
        $publisher = $this->publisher();
        $other = $this->publisher(['slug' => 'akademnashr', 'name' => 'Akademnashr']);
        $translator = $this->translator();

        $this->book($translator, $publisher, 'Chol va dengiz');
        $this->book($translator, $publisher, 'Alvido, qurol');
        $this->book($translator, $other, 'Qor');

        $counts = collect($this->getJson('/api/publishers')->assertOk()->json('data'))
            ->pluck('bookCount', 'slug');

        $this->assertSame(2, $counts['yangi-asr-avlodi']);
        $this->assertSame(1, $counts['akademnashr']);
    }

    public function test_the_collection_is_ordered_by_name(): void
    {
        $this->publisher();
        $this->publisher(['slug' => 'akademnashr', 'name' => 'Akademnashr']);

        $this->getJson('/api/publishers')
            ->assertJsonPath('data.0.name', 'Akademnashr')
            ->assertJsonPath('data.1.name', 'Yangi asr avlodi');
    }

    public function test_translatable_fields_follow_the_requested_locale(): void
    {
        $this->publisher([
            'bio' => ['uz' => "Toshkentdagi nashriyot.", 'ru' => 'Издательство в Ташкенте.'],
            'country' => ['uz' => "O'zbekiston", 'ru' => 'Узбекистан'],
        ]);

        $this->getJson('/api/publishers?locale=ru')
            ->assertJsonPath('data.0.bio', 'Издательство в Ташкенте.')
            ->assertJsonPath('data.0.country', 'Узбекистан');

        $this->getJson('/api/publishers?locale=uz')
            ->assertJsonPath('data.0.country', "O'zbekiston");
    }

    public function test_a_locale_with_no_translation_falls_back_rather_than_blanking(): void
    {
        $this->publisher(['bio' => ['uz' => 'Toshkentdagi nashriyot.']]);

        $this->getJson('/api/publishers?locale=en')
            ->assertJsonPath('data.0.bio', 'Toshkentdagi nashriyot.');
    }

    public function test_a_single_publisher_can_be_fetched(): void
    {
        $publisher = $this->publisher();
        $this->book($this->translator(), $publisher, 'Chol va dengiz');

        $this->getJson("/api/publishers/{$publisher->id}")
            ->assertOk()
            ->assertJsonPath('data.slug', 'yangi-asr-avlodi')
            ->assertJsonPath('data.bookCount', 1);
    }

    public function test_an_unknown_publisher_is_a_404(): void
    {
        $this->getJson('/api/publishers/404404')->assertNotFound();
    }

    public function test_the_endpoints_need_no_authentication(): void
    {
        $this->publisher();

        $this->getJson('/api/publishers')->assertOk();
    }
}
