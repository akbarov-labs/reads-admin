<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Translator` shape in the Next.js app's lib/types.ts.
 */
class TranslatorResource extends JsonResource
{
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
        ];
    }
}
