<?php

namespace App\Http\Resources\Api;

use App\Support\TranslatorSeo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Translator` shape in the Next.js app's lib/types.ts.
 */
class TranslatorResource extends JsonResource
{
    /**
     * The controller has already validated ?locale= and applied it to the
     * app; reading it back keeps this resource honest if it is ever rendered
     * outside that controller.
     */
    private function requestedLocale(): string
    {
        $locale = app()->getLocale();

        return in_array($locale, TranslatorSeo::LOCALES, true)
            ? $locale
            : 'uz';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'title' => $this->title ?: null,
            'avatarUrl' => $this->avatar_url,
            'location' => $this->location,
            'bio' => $this->bio,
            'languagePairs' => $this->languagePairs->map(fn ($pair) => [
                'from' => $pair->from_language,
                'to' => $pair->to_language,
            ]),
            'socialLinks' => $this->socialLinks->map(fn ($link) => [
                'platform' => $link->platform->value,
                'url' => $link->url,
            ]),
            'yearsActive' => $this->years_active,
            'startYear' => $this->start_year,
            'totalBooksTranslated' => $this->books->count(),
            'books' => BookResource::collection($this->books),

            // Sitemap <lastmod>. Covers the whole page, not just the
            // translator row — a new book changes what the page says.
            'updatedAt' => $this->content_updated_at?->toAtomString(),

            // Already resolved for the requested locale: an admin override
            // when one exists, otherwise a value derived from this profile.
            // The site renders these verbatim, so what an admin previews in
            // the panel is exactly what search engines index.
            'seo' => $this->resource->seo()->toArray($this->requestedLocale()),
        ];
    }
}
