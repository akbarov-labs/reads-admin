<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Book;
use App\Models\LanguagePair;
use App\Models\Translator;
use App\Models\User;
use App\Support\TranslatorSeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslatorSeoTest extends TestCase
{
    use RefreshDatabase;

    private function translator(array $attributes = []): Translator
    {
        $user = User::create([
            'name' => 'Ilhom Salimov',
            'email' => 'ilhom@example.test',
            'password' => 'password',
            'role' => UserRole::Translator,
        ]);

        return Translator::create(array_merge([
            'user_id' => $user->id,
            'slug' => 'ilhom-salimov',
            'name' => 'Ilhom Salimov',
            'years_active' => 5,
        ], $attributes));
    }

    public function test_title_is_derived_from_the_name_and_professional_title(): void
    {
        $translator = $this->translator(['title' => ['en' => 'Literary translator']]);

        $this->assertSame(
            'Ilhom Salimov — Literary translator',
            $translator->seo()->title('en'),
        );
    }

    public function test_title_falls_back_to_a_generic_role_per_locale(): void
    {
        $translator = $this->translator();

        $this->assertSame('Ilhom Salimov — Tarjimon', $translator->seo()->title('uz'));
        $this->assertSame('Ilhom Salimov — Переводчик', $translator->seo()->title('ru'));
    }

    public function test_description_prefers_the_bio(): void
    {
        $translator = $this->translator([
            'bio' => ['en' => 'Translates French and English fiction into Uzbek.'],
        ]);

        $this->assertSame(
            'Translates French and English fiction into Uzbek.',
            $translator->seo()->description('en'),
        );
    }

    public function test_description_is_assembled_from_the_profile_when_there_is_no_bio(): void
    {
        $translator = $this->translator(['location' => ['en' => 'Tashkent']]);

        LanguagePair::create([
            'translator_id' => $translator->id,
            'from_language' => 'French',
            'to_language' => 'Uzbek',
        ]);

        $description = $translator->fresh()->seo()->description('en');

        $this->assertStringContainsString('Tashkent', $description);
        $this->assertStringContainsString('French–Uzbek', $description);
    }

    /**
     * The whole point of deriving the description instead of storing it: the
     * moment a book is added, the site's snippet says so.
     */
    public function test_derived_description_counts_books_as_they_are_added(): void
    {
        $translator = $this->translator();

        $this->assertStringContainsString(
            'literary translations',
            $translator->seo()->description('en'),
        );

        Book::create([
            'translator_id' => $translator->id,
            'uzbek_title' => 'Maymunlar sayyorasi',
            'original_title' => 'La Planète des singes',
            'author' => 'Pierre Boulle',
            'source_language' => 'French',
        ]);

        $this->assertStringContainsString(
            '1 published books and translations',
            $translator->fresh()->seo()->description('en'),
        );
    }

    public function test_an_admin_override_wins_over_the_derived_value(): void
    {
        $translator = $this->translator([
            'meta_title' => ['en' => 'Hand-written title'],
            'meta_description' => ['en' => 'Hand-written description'],
        ]);

        $this->assertSame('Hand-written title', $translator->seo()->title('en'));
        $this->assertSame('Hand-written description', $translator->seo()->description('en'));
    }

    public function test_descriptions_are_clamped_to_a_snippet_length(): void
    {
        $translator = $this->translator(['bio' => ['en' => str_repeat('word ', 100)]]);

        $this->assertLessThanOrEqual(
            160,
            mb_strlen($translator->seo()->description('en')),
        );
    }

    public function test_a_translator_cannot_write_their_own_seo_fields(): void
    {
        $translator = $this->translator(['meta_title' => ['en' => 'Set by an admin']]);

        $this->actingAs($translator->user);

        $translator->update([
            'bio' => ['en' => 'A legitimate edit'],
            'meta_title' => ['en' => 'Injected by the translator'],
            'seo_noindex' => true,
            'slug' => 'a-slug-they-chose',
        ]);

        $fresh = $translator->fresh();

        // The admin-only fields are untouched...
        $this->assertSame('Set by an admin', $fresh->getTranslation('meta_title', 'en'));
        $this->assertFalse($fresh->seo_noindex);
        $this->assertSame('ilhom-salimov', $fresh->slug);

        // ...while the rest of the save went through as normal.
        $this->assertSame('A legitimate edit', $fresh->getTranslation('bio', 'en'));
    }

    public function test_an_admin_can_write_seo_fields(): void
    {
        $translator = $this->translator();

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin);

        $translator->update([
            'meta_title' => ['en' => 'Chosen by the admin'],
            'seo_noindex' => true,
        ]);

        $fresh = $translator->fresh();

        $this->assertSame('Chosen by the admin', $fresh->getTranslation('meta_title', 'en'));
        $this->assertTrue($fresh->seo_noindex);
    }

    public function test_the_api_returns_resolved_seo_for_the_requested_locale(): void
    {
        $this->translator(['title' => ['ru' => 'Переводчик и автор']]);

        $this->getJson('/api/translators?locale=ru')
            ->assertOk()
            ->assertJsonPath('data.0.seo.metaTitle', 'Ilhom Salimov — Переводчик и автор')
            ->assertJsonPath('data.0.seo.noindex', false)
            ->assertJsonStructure([
                'data' => [['seo' => ['metaTitle', 'metaDescription', 'ogImageUrl', 'noindex'], 'updatedAt']],
            ]);
    }

    public function test_supported_locales_match_the_sites_routing(): void
    {
        $this->assertSame(['uz', 'ru', 'en'], TranslatorSeo::LOCALES);
    }
}
