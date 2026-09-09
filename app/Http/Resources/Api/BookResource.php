<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Book` / `Excerpt` shape in the Next.js app's lib/types.ts.
 */
class BookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'role' => $this->role?->value,
            'uzbekTitle' => $this->uzbek_title,
            'originalTitle' => $this->original_title,
            'author' => $this->author,
            'sourceLanguage' => $this->source_language,
            'publisher' => $this->publisher,
            'year' => $this->year,
            'coverUrl' => $this->cover_url,
            'excerpt' => $this->hasExcerpt() ? [
                'sourceLanguage' => $this->excerpt_source_language,
                'sourceText' => $this->excerpt_source_text,
                'translatedText' => $this->excerpt_translated_text,
            ] : null,
        ];
    }
}
