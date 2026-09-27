<?php

namespace App\Support;

use App\Models\Translator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Resolves the search-engine metadata for one translator: the admin's
 * override when there is one, otherwise a value derived from the profile.
 *
 * The derived values are deliberately computed on read rather than stored,
 * so adding a book or editing a bio changes the title/description on the
 * next request without anyone re-saving an SEO form.
 *
 * The public site consumes the resolved values verbatim (see the `seo` block
 * in App\Http\Resources\Api\TranslatorResource), which means the preview an
 * admin sees in the panel is the exact string that reaches Google.
 */
class TranslatorSeo
{
    /** Keep in sync with Reads/i18n/routing.ts. */
    public const LOCALES = ['uz', 'ru', 'en'];

    /** Search results truncate past roughly this much; aim just under. */
    private const TITLE_LIMIT = 60;

    private const DESCRIPTION_LIMIT = 155;

    /**
     * Per-locale wording for the derived defaults. `:name` etc. are
     * substituted below — no user input is ever interpolated as a pattern.
     *
     * @var array<string, array<string, string>>
     */
    private const STRINGS = [
        'uz' => [
            'role' => 'Tarjimon',
            'title' => ':name — :role',
            'summary' => ':name — :role. :books va :pairs.',
            'books' => ':count ta kitob va tarjima',
            'no_books' => 'kitob tarjimalari',
            'in' => ':location, ',
        ],
        'ru' => [
            'role' => 'Переводчик',
            'title' => ':name — :role',
            'summary' => ':name — :role. :books, :pairs.',
            'books' => ':count книг и переводов',
            'no_books' => 'литературные переводы',
            'in' => ':location, ',
        ],
        'en' => [
            'role' => 'Literary translator',
            'title' => ':name — :role',
            'summary' => ':name is a :role. :books, :pairs.',
            'books' => ':count published books and translations',
            'no_books' => 'literary translations',
            'in' => 'Based in :location. ',
        ],
    ];

    public function __construct(private readonly Translator $translator) {}

    public static function for(Translator $translator): self
    {
        return new self($translator);
    }

    /**
     * The full <title> for the profile page, override first.
     */
    public function title(string $locale): string
    {
        $override = $this->translation('meta_title', $locale);

        return filled($override)
            ? $this->collapse($override)
            : $this->defaultTitle($locale);
    }

    /**
     * What the title would be with no override — what the panel previews in
     * the empty field, so an admin can see the automatic value before
     * deciding whether it needs replacing.
     */
    public function defaultTitle(string $locale): string
    {
        $strings = self::strings($locale);
        $role = $this->translation('title', $locale) ?: $strings['role'];

        $title = strtr($strings['title'], [
            ':name' => $this->translator->name,
            ':role' => $role,
        ]);

        // No ellipsis on a title: a clipped '…' inside <title> reads as a
        // rendering bug, whereas Google simply truncates the tail itself.
        return $this->clamp($this->collapse($title), self::TITLE_LIMIT, '');
    }

    /**
     * The meta description, in order of preference: admin override, the
     * translator's own bio, then a sentence assembled from the profile.
     */
    public function description(string $locale): string
    {
        $override = $this->translation('meta_description', $locale);

        return filled($override)
            ? $this->clamp($this->collapse($override), self::DESCRIPTION_LIMIT)
            : $this->defaultDescription($locale);
    }

    /** @see defaultTitle() — same purpose, for the description field. */
    public function defaultDescription(string $locale): string
    {
        $bio = $this->translation('bio', $locale);

        return $this->clamp(
            filled($bio) ? $this->collapse($bio) : $this->derivedSummary($locale),
            self::DESCRIPTION_LIMIT,
        );
    }

    /**
     * Absolute, publicly reachable URL — unlike avatar_url/cover_url, which
     * stay relative for the API's consumer to resolve. A social-preview image
     * is fetched by Facebook/Telegram/X from the open internet, so it has to
     * carry this panel's real public host (APP_URL) even though the site
     * itself reaches us over a private Docker network.
     */
    public function ogImageUrl(): ?string
    {
        $path = $this->translator->og_image_path ?: $this->translator->avatar_path;

        return $path ? Storage::disk('public')->url($path) : null;
    }

    public function noindex(): bool
    {
        return (bool) $this->translator->seo_noindex;
    }

    /**
     * Resolved payload for one locale — the shape the public site consumes.
     *
     * @return array<string, mixed>
     */
    public function toArray(string $locale): array
    {
        return [
            'metaTitle' => $this->title($locale),
            'metaDescription' => $this->description($locale),
            'ogImageUrl' => $this->ogImageUrl(),
            'noindex' => $this->noindex(),
        ];
    }

    /**
     * Assembled from whatever the profile actually has: book count, language
     * pairs, location. Every piece is optional, so this degrades to just the
     * name and role rather than to a sentence with holes in it.
     */
    private function derivedSummary(string $locale): string
    {
        $strings = self::strings($locale);
        $role = $this->translation('title', $locale) ?: $strings['role'];

        $count = $this->translator->total_books_translated;

        $books = $count > 0
            ? strtr($strings['books'], [':count' => (string) $count])
            : $strings['no_books'];

        $pairs = $this->translator->languagePairs
            ->map(fn ($pair) => $pair->from_language.'–'.$pair->to_language)
            ->filter()
            ->take(3)
            ->implode(', ');

        $summary = strtr($strings['summary'], [
            ':name' => $this->translator->name,
            ':role' => $role,
            ':books' => $books,
            ':pairs' => $pairs,
        ]);

        // No language pairs recorded yet: drop the dangling ", ." the
        // template would otherwise leave behind.
        if ($pairs === '') {
            $summary = rtrim(str_replace(', .', '.', $summary));
        }

        $location = $this->translation('location', $locale);

        if (filled($location)) {
            $summary = strtr($strings['in'], [':location' => $location]).$summary;
        }

        return $this->collapse($summary);
    }

    /**
     * Reads one translatable attribute in a specific locale *without*
     * changing the app locale, and without spatie's "any other locale"
     * fallback — a Russian description must not silently become the Uzbek
     * one here, because hreflang has already told Google this page is ru.
     * Uzbek is the site default, so it is the one accepted stand-in.
     */
    private function translation(string $attribute, string $locale): ?string
    {
        $value = $this->translator->getTranslation($attribute, $locale, false);

        if (blank($value) && $locale !== 'uz') {
            $value = $this->translator->getTranslation($attribute, 'uz', false);
        }

        return blank($value) ? null : (string) $value;
    }

    /** @return array<string, string> */
    private static function strings(string $locale): array
    {
        return self::STRINGS[$locale] ?? self::STRINGS['uz'];
    }

    private function collapse(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function clamp(string $value, int $limit, string $end = '…'): string
    {
        return rtrim(Str::limit($value, $limit, $end, preserveWords: true));
    }
}
